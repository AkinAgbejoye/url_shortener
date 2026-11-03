<?php

namespace App\Http\Controllers;

use App\Jobs\ImportVerifiedSite;
use App\Models\DomainVerification;
use App\Models\SiteImport;
use App\Models\User;
use App\Services\SiteImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class SiteImportController extends Controller
{
    public function index(Request $request): View
    {
        $owner = $this->owner($request);

        return view('site-imports', [
            'verifications' => DomainVerification::query()->ownedBy($owner)->whereNull('revoked_at')->where('verified_until', '>', now())->latest()->get(),
            'imports' => SiteImport::query()->ownedBy($owner)->with('verification')->latest()->limit(50)->get(),
        ]);
    }

    public function store(Request $request, SiteImportService $service): RedirectResponse
    {
        $input = $request->validate([
            'verification_id' => ['required', 'string', 'max:40'],
            'rights_attestation' => ['required', 'string', 'min:40', 'max:1000'],
            'declared_licenses' => ['required', 'string', 'max:2000'],
        ]);
        $verification = DomainVerification::query()->ownedBy($this->owner($request))->where('public_id', $input['verification_id'])->firstOrFail();
        $licenses = array_values(array_filter(array_map('trim', preg_split('/\R/', $input['declared_licenses']) ?: [])));
        try {
            $import = $service->create($this->owner($request), $verification, $input['rights_attestation'], array_slice($licenses, 0, 20));
        } catch (RuntimeException $exception) {
            return back()->withErrors(['import' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Authorized import '.$import->public_id.' queued.');
    }

    public function preview(Request $request, string $importId): Response
    {
        $import = $this->owned($request, $importId);
        abort_unless(in_array($import->state, ['preview_ready', 'partially_ready', 'confirmed'], true) && $import->authorized(), 404);

        return response()->view('site-import-preview', ['import' => $import], 200, [
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:; frame-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    public function control(Request $request, string $importId): RedirectResponse
    {
        $import = $this->owned($request, $importId);
        $action = $request->validate(['action' => ['required', Rule::in(['confirm', 'cancel', 'retry', 'reauthorize', 'takedown', 'delete'])]])['action'];
        if ($action === 'cancel' && in_array($import->state, ['queued', 'importing'], true)) {
            $import->forceFill(['state' => 'cancelled', 'cancel_requested_at' => now(), 'completed_at' => now()])->save();
        }
        if ($action === 'confirm' && in_array($import->state, ['preview_ready', 'partially_ready'], true) && $import->authorized()) {
            $import->forceFill(['state' => 'confirmed', 'confirmed_at' => now()])->save();
        }
        if ($action === 'takedown') {
            $this->removeContent($import, 'taken_down', 'takedown_at');
        }
        if ($action === 'delete') {
            $this->removeContent($import, 'deleted', 'deleted_at');
        }
        if ($action === 'retry' && in_array($import->state, ['failed', 'cancelled', 'partially_ready'], true) && $import->authorized()) {
            $import->assets()->delete();
            $import->forceFill([
                'state' => 'queued', 'cancel_requested_at' => null, 'completed_at' => null, 'safe_failure_category' => null,
                'sanitized_html' => null, 'editable_representation' => null, 'sanitation_summary' => null, 'provenance' => null,
            ])->save();
            ImportVerifiedSite::dispatch($import->id)->onQueue('intelligence');
        }
        if ($action === 'reauthorize') {
            $input = $request->validate([
                'verification_id' => ['required', 'string', 'max:40'],
                'rights_attestation' => ['required', 'string', 'min:40', 'max:1000'],
            ]);
            $verification = DomainVerification::query()->ownedBy($this->owner($request))->where('public_id', $input['verification_id'])->firstOrFail();
            abort_unless($verification->current() && hash_equals($import->verification->domain_hash, $verification->domain_hash), 422);
            $rightsLimit = now()->addDays(max(1, (int) config('website_intelligence.imports.rights_days', 7)));
            $import->forceFill([
                'domain_verification_id' => $verification->id,
                'rights_attestation' => trim($input['rights_attestation']),
                'rights_attested_at' => now(),
                'rights_expires_at' => $verification->verified_until->lessThan($rightsLimit) ? $verification->verified_until : $rightsLimit,
            ])->save();
        }

        return back()->with('status', 'Import control updated.');
    }

    private function removeContent(SiteImport $import, string $state, string $timestamp): void
    {
        $import->assets()->delete();
        $import->forceFill([$timestamp => now(), 'state' => $state, 'sanitized_html' => null, 'editable_representation' => null])->save();
    }

    private function owned(Request $request, string $id): SiteImport
    {
        return SiteImport::query()->ownedBy($this->owner($request))->where('public_id', $id)->with(['verification', 'assets'])->firstOrFail();
    }

    private function owner(Request $request): User
    {
        /** @var User $owner */
        $owner = $request->user();

        return $owner;
    }
}
