<?php

namespace App\Services;

use App\Jobs\CrawlVerifiedDomain;
use App\Models\CrawlJob;
use App\Models\DomainVerification;
use App\Models\ModerationDomainBlock;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CrawlJobService
{
    /** @param array<string, mixed> $policy */
    public function create(User $owner, DomainVerification $verification, array $policy): CrawlJob
    {
        if ($verification->owner_id !== $owner->id || ! $verification->current()
            || ModerationDomainBlock::query()->where('domain_hash', $verification->domain_hash)->whereNull('released_at')->exists()
            || $owner->intelligence_suspended_at !== null) {
            throw new RuntimeException('The verified domain is not eligible for crawling.');
        }
        $includePaths = $this->paths($policy['include_paths'] ?? ['/']);
        $normalized = [
            'include_paths' => $includePaths === [] ? ['/'] : $includePaths,
            'exclude_paths' => $this->paths($policy['exclude_paths'] ?? []),
            'max_pages' => min(max(1, (int) ($policy['max_pages'] ?? 25)), (int) config('website_intelligence.crawling.max_pages', 100)),
            'max_depth' => min(max(0, (int) ($policy['max_depth'] ?? 3)), (int) config('website_intelligence.crawling.max_depth', 5)),
            'max_bytes' => min(max(1024, (int) ($policy['max_bytes'] ?? 5000000)), (int) config('website_intelligence.crawling.max_bytes', 20000000)),
        ];
        $job = DB::transaction(function () use ($owner, $verification, $normalized): CrawlJob {
            $active = CrawlJob::query()->where('owner_id', $owner->id)->whereIn('state', ['queued', 'running', 'paused'])->lockForUpdate()->count();
            if ($active >= max(1, (int) config('website_intelligence.crawling.max_active_per_account', 1))) {
                throw new RuntimeException('A crawl is already active for this account.');
            }

            return CrawlJob::create([
                'owner_id' => $owner->id, 'domain_verification_id' => $verification->id,
                'public_id' => 'crawl_'.Str::ulid(), 'state' => 'queued', 'policy' => $normalized,
                'expires_at' => now()->addDays(max(1, (int) config('website_intelligence.budgets.retention_days', 30))),
            ]);
        }, 3);
        CrawlVerifiedDomain::dispatch($job->id)->onQueue('intelligence');

        return $job;
    }

    /** @return list<string> */
    private function paths(mixed $paths): array
    {
        if (! is_array($paths)) {
            return [];
        }

        return array_slice(array_values(array_unique(array_filter(array_map(
            fn (mixed $path): string => is_string($path) && str_starts_with($path, '/') ? mb_strcut($path, 0, 200, 'UTF-8') : '',
            $paths,
        )))), 0, 20);
    }
}
