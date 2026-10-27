<?php

namespace App\Services;

use App\Contracts\IntelligenceDependencyProbe;
use Illuminate\Support\Facades\Cache;
use Throwable;

class IntelligenceReadinessService
{
    private const CHECKS = ['database', 'queue', 'cache', 'lock', 'storage', 'worker'];

    public function __construct(private readonly IntelligenceDependencyProbe $probe) {}

    /** @return array{status: 'ready'|'not_ready', checked_at: string, checks: array<string, string>} */
    public function snapshot(): array
    {
        $cacheSeconds = max(0, (int) config('website_intelligence.readiness.cache_seconds', 5));

        if ($cacheSeconds === 0) {
            return $this->run();
        }

        try {
            return Cache::remember(
                'website-intelligence:readiness:snapshot',
                $cacheSeconds,
                fn (): array => $this->run(),
            );
        } catch (Throwable) {
            return $this->run();
        }
    }

    /** @return array{status: 'ready'|'not_ready', checked_at: string, checks: array<string, string>} */
    private function run(): array
    {
        $raw = $this->probe->check();
        $timeout = max(1, (int) config('website_intelligence.readiness.check_timeout_ms', 1000));
        $checks = [];

        foreach (self::CHECKS as $name) {
            $result = $raw[$name] ?? ['status' => 'not_ready', 'duration_ms' => $timeout + 1];
            $status = in_array($result['status'] ?? null, ['ready', 'not_ready', 'disabled'], true)
                ? $result['status']
                : 'not_ready';
            $checks[$name] = ($result['duration_ms'] ?? $timeout + 1) > $timeout
                ? 'not_ready'
                : $status;
        }

        return [
            'status' => in_array('not_ready', $checks, true) ? 'not_ready' : 'ready',
            'checked_at' => now()->utc()->toIso8601String(),
            'checks' => $checks,
        ];
    }
}
