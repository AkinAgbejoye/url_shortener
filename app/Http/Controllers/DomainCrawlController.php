<?php

namespace App\Http\Controllers;

use App\Jobs\CrawlVerifiedDomain;
use App\Models\CrawlJob;
use App\Models\DomainVerification;
use App\Models\User;
use App\Services\CrawlJobService;
use App\Services\DomainVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;

class DomainCrawlController extends Controller
{
    public function index(Request $request): View
    {
        $owner = $this->owner($request);

        return view('domain-crawls', [
            'verifications' => DomainVerification::query()->ownedBy($owner)->latest()->get(),
            'crawls' => CrawlJob::query()->ownedBy($owner)->withCount('pages')->latest()->limit(50)->get(),
        ]);
    }

    public function challenge(Request $request, DomainVerificationService $service): RedirectResponse
    {
        $input = $request->validate(['domain' => ['required', 'string', 'max:253'], 'method' => ['required', Rule::in(['dns_txt', 'well_known', 'html_meta'])]]);
        try {
            $result = $service->challenge($this->owner($request), $input['domain'], $input['method']);
        } catch (InvalidArgumentException) {
            return back()->withErrors(['domain' => 'Enter an eligible public domain.']);
        }

        return back()->with('verification_token', $result['token'])->with('verification_id', $result['verification']->public_id);
    }

    public function confirm(Request $request, string $verificationId, DomainVerificationService $service): RedirectResponse
    {
        $verification = $this->verification($request, $verificationId);

        return $service->confirm($verification)
            ? back()->with('status', 'Domain control verified for a limited period.')
            : back()->withErrors(['verification' => 'Verification could not be confirmed.']);
    }

    public function revoke(Request $request, string $verificationId): RedirectResponse
    {
        $verification = $this->verification($request, $verificationId);
        $verification->forceFill(['revoked_at' => now(), 'verified_until' => now()])->save();
        $verification->crawlJobs()->whereIn('state', ['queued', 'running', 'paused'])->update(['state' => 'cancelled', 'cancel_requested_at' => now(), 'completed_at' => now(), 'updated_at' => now()]);

        return back()->with('status', 'Verification revoked and active crawls stopped.');
    }

    public function crawl(Request $request, string $verificationId, CrawlJobService $service): RedirectResponse
    {
        $verification = $this->verification($request, $verificationId);
        $input = $request->validate([
            'include_paths' => ['nullable', 'array', 'max:20'], 'include_paths.*' => ['string', 'starts_with:/', 'max:200'],
            'exclude_paths' => ['nullable', 'array', 'max:20'], 'exclude_paths.*' => ['string', 'starts_with:/', 'max:200'],
            'max_pages' => ['nullable', 'integer', 'min:1'], 'max_depth' => ['nullable', 'integer', 'min:0'], 'max_bytes' => ['nullable', 'integer', 'min:1024'],
        ]);
        try {
            $job = $service->create($this->owner($request), $verification, $input);
        } catch (RuntimeException) {
            return back()->withErrors(['crawl' => 'The crawl is not eligible or another crawl is active.']);
        }

        return back()->with('status', 'Bounded crawl '.$job->public_id.' queued.');
    }

    public function control(Request $request, string $crawlId): RedirectResponse
    {
        $job = $this->crawlJob($request, $crawlId);
        $action = $request->validate(['action' => ['required', Rule::in(['pause', 'resume', 'cancel', 'delete'])]])['action'];
        if ($action === 'delete') {
            $job->delete();

            return back()->with('status', 'Crawl and retained page evidence deleted.');
        }
        if ($action === 'pause' && in_array($job->state, ['queued', 'running'], true)) {
            $job->forceFill(['state' => 'paused'])->save();
        }
        if ($action === 'resume' && $job->state === 'paused' && $job->verification->current()) {
            $job->forceFill(['state' => 'queued'])->save();
            CrawlVerifiedDomain::dispatch($job->id)->onQueue('intelligence');
        }
        if ($action === 'cancel' && in_array($job->state, ['queued', 'running', 'paused'], true)) {
            $job->forceFill(['state' => 'cancelled', 'cancel_requested_at' => now(), 'completed_at' => now()])->save();
        }

        return back()->with('status', 'Crawl control updated.');
    }

    private function verification(Request $request, string $id): DomainVerification
    {
        return DomainVerification::query()->ownedBy($this->owner($request))->where('public_id', $id)->firstOrFail();
    }

    private function crawlJob(Request $request, string $id): CrawlJob
    {
        return CrawlJob::query()->ownedBy($this->owner($request))->where('public_id', $id)->firstOrFail();
    }

    private function owner(Request $request): User
    {
        /** @var User $owner */
        $owner = $request->user();

        return $owner;
    }
}
