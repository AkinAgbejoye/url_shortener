<?php

namespace App\Services;

use App\Enums\AnalysisState;
use App\Exceptions\AnalysisConflict;
use App\Exceptions\AnalysisQuotaExceeded;
use App\Jobs\AcquireSinglePage;
use App\Models\AnalysisJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AnalysisJobService
{
    public function __construct(private readonly AcquisitionUrlPolicy $policy) {}

    /** @param list<string> $userContext @return array{job: AnalysisJob, replayed: bool} */
    public function create(User $owner, string $url, string $attestation, array $userContext, string $idempotencyKey): array
    {
        $target = $this->policy->parse($url);
        $secret = (string) config('app.key');
        $keyHash = hash_hmac('sha256', $idempotencyKey, $secret);
        $intentHash = hash_hmac('sha256', json_encode([
            $target->url, trim($attestation), array_values($userContext),
        ], JSON_THROW_ON_ERROR), $secret);
        $origin = $target->scheme.'://'.(str_contains($target->host, ':') ? '['.$target->host.']' : $target->host);
        $originHash = hash_hmac('sha256', $origin, $secret);
        $domainHash = hash_hmac('sha256', $target->host, $secret);

        $result = DB::transaction(function () use ($owner, $target, $origin, $originHash, $domainHash, $keyHash, $intentHash, $userContext): array {
            $existing = AnalysisJob::query()->ownedBy($owner)->where('idempotency_key_hash', $keyHash)->lockForUpdate()->first();
            if ($existing !== null) {
                if (! hash_equals((string) $existing->intent_hash, $intentHash)) {
                    throw new AnalysisConflict('The idempotency key was already used for another request.');
                }

                return ['job' => $existing, 'replayed' => true];
            }

            $active = array_map(fn (AnalysisState $state): string => $state->value, array_filter(AnalysisState::cases(), fn (AnalysisState $state): bool => $state->active()));
            $base = AnalysisJob::query()->whereIn('state', $active)->lockForUpdate();
            if ((clone $base)->where('owner_id', $owner->getKey())->count() >= max(1, (int) config('website_intelligence.budgets.max_active_per_account', 3))
                || (clone $base)->where('origin_hash', $originHash)->count() >= max(1, (int) config('website_intelligence.budgets.max_active_per_origin', 1))
                || (clone $base)->where('domain_hash', $domainHash)->count() >= max(1, (int) config('website_intelligence.budgets.max_active_per_domain', 2))
                || (clone $base)->count() >= max(1, (int) config('website_intelligence.budgets.max_active_global', 20))) {
                throw new AnalysisQuotaExceeded('An analysis quota is currently full.');
            }

            $job = AnalysisJob::create([
                'owner_id' => $owner->getKey(),
                'public_id' => 'ana_'.Str::ulid(),
                'target_url' => $target->url,
                'submitted_origin' => $origin,
                'origin_hash' => $originHash,
                'domain_hash' => $domainHash,
                'state' => AnalysisState::Queued,
                'idempotency_key_hash' => $keyHash,
                'intent_hash' => $intentHash,
                'user_context' => array_values($userContext),
                'expires_at' => now()->addDays(max(1, (int) config('website_intelligence.budgets.retention_days', 30))),
            ]);

            return ['job' => $job, 'replayed' => false];
        }, 3);

        if (! $result['replayed']) {
            AcquireSinglePage::dispatch($result['job']->getKey())->onQueue('intelligence');
        }

        return $result;
    }
}
