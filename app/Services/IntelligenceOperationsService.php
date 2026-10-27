<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class IntelligenceOperationsService
{
    private const SECTIONS = [
        'queue' => ['oldest_job_age_seconds', 'active_jobs', 'queued_jobs', 'worker_capacity', 'worker_busy'],
        'outcomes' => ['completed', 'partially_completed', 'blocked', 'failed', 'cancelled'],
        'blocked_destinations' => ['dns', 'private_network', 'unsupported_scheme', 'redirect_policy', 'domain_policy'],
        'validation_failures' => ['sanitizer', 'schema'],
        'deletion' => ['pending', 'overdue'],
    ];

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        try {
            $raw = Cache::get((string) config('website_intelligence.operations.snapshot_key'));
        } catch (Throwable) {
            $raw = null;
        }

        $generatedAt = is_array($raw) && is_string($raw['generated_at'] ?? null)
            ? $this->safeTimestamp($raw['generated_at'])
            : null;
        $sections = [];
        foreach (self::SECTIONS as $section => $keys) {
            foreach ($keys as $key) {
                $sections[$section][$key] = $this->boundedInteger($raw[$section][$key] ?? 0);
            }
        }

        $staleAfter = max(1, (int) config('website_intelligence.operations.stale_seconds', 120));
        $signal = $generatedAt === null
            ? 'missing'
            : (CarbonImmutable::parse($generatedAt)->lt(now()->subSeconds($staleAfter)) ? 'stale' : 'fresh');

        return [
            'signal' => $signal,
            'generated_at' => $generatedAt,
            ...$sections,
            'budgets' => [
                'max_concurrent_jobs' => $this->budget('max_concurrent_jobs'),
                'max_queue_age_seconds' => $this->budget('max_queue_age_seconds'),
                'max_job_runtime_seconds' => $this->budget('max_job_runtime_seconds'),
                'max_response_bytes' => $this->budget('max_response_bytes'),
                'retention_days' => $this->budget('retention_days'),
            ],
        ];
    }

    private function safeTimestamp(string $timestamp): ?string
    {
        try {
            return CarbonImmutable::parse($timestamp)->utc()->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    private function boundedInteger(mixed $value): int
    {
        return is_int($value) ? min(1_000_000_000, max(0, $value)) : 0;
    }

    private function budget(string $key): int
    {
        return $this->boundedInteger((int) config("website_intelligence.budgets.{$key}", 0));
    }
}
