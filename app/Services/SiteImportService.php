<?php

namespace App\Services;

use App\Jobs\ImportVerifiedSite;
use App\Models\DomainVerification;
use App\Models\ModerationDomainBlock;
use App\Models\SiteImport;
use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

class SiteImportService
{
    /** @param list<string> $licenses */
    public function create(User $owner, DomainVerification $verification, string $attestation, array $licenses): SiteImport
    {
        $verification->refresh();
        if ($verification->owner_id !== $owner->id || ! $owner->hasVerifiedEmail() || ! $verification->current()
            || $owner->intelligence_suspended_at !== null
            || ModerationDomainBlock::query()->where('domain_hash', $verification->domain_hash)->whereNull('released_at')->exists()) {
            throw new RuntimeException('authorization_required');
        }
        if (mb_strlen(trim($attestation)) < 40 || mb_strlen($attestation) > 1000 || $licenses === [] || count($licenses) > 20
            || array_filter($licenses, fn ($license): bool => ! is_string($license) || mb_strlen(trim($license)) < 1 || mb_strlen($license) > 200) !== []) {
            throw new RuntimeException('rights_attestation_required');
        }
        $active = SiteImport::query()->ownedBy($owner)->whereIn('state', ['queued', 'importing'])->count();
        if ($active >= max(1, (int) config('website_intelligence.imports.max_active_per_account', 1))) {
            throw new RuntimeException('import_quota_full');
        }
        $now = now();
        $rightsLimit = $now->copy()->addDays(max(1, (int) config('website_intelligence.imports.rights_days', 7)));
        $import = SiteImport::create([
            'owner_id' => $owner->id,
            'domain_verification_id' => $verification->id,
            'public_id' => 'import_'.Str::ulid(),
            'state' => 'queued',
            'rights_attestation' => trim($attestation),
            'declared_licenses' => array_values(array_map('trim', $licenses)),
            'rights_attested_at' => $now,
            'rights_expires_at' => $verification->verified_until->lessThan($rightsLimit) ? $verification->verified_until : $rightsLimit,
            'policy' => [
                'max_pages' => max(1, (int) config('website_intelligence.imports.max_pages', 1)),
                'max_assets' => max(1, (int) config('website_intelligence.imports.max_assets', 20)),
                'max_total_bytes' => max(1024, (int) config('website_intelligence.imports.max_total_bytes', 10000000)),
            ],
            'expires_at' => $now->copy()->addDays(max(1, (int) config('website_intelligence.budgets.retention_days', 30))),
        ]);
        ImportVerifiedSite::dispatch($import->id)->onQueue('intelligence');

        return $import;
    }
}
