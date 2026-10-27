<?php

namespace Tests\Unit;

use App\Contracts\IntelligenceDependencyProbe;
use App\Services\IntelligenceReadinessService;
use App\Services\SystemIntelligenceDependencyProbe;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class IntelligenceReadinessTest extends TestCase
{
    public function test_snapshot_is_cached_for_the_configured_window(): void
    {
        config(['website_intelligence.readiness.cache_seconds' => 30]);
        Cache::forget('website-intelligence:readiness:snapshot');
        $probe = new class implements IntelligenceDependencyProbe
        {
            public int $calls = 0;

            public function check(): array
            {
                $this->calls++;

                return collect(['database', 'queue', 'cache', 'lock', 'storage', 'worker'])
                    ->mapWithKeys(fn (string $name): array => [$name => ['status' => 'ready', 'duration_ms' => 1.0]])
                    ->all();
            }
        };
        $service = new IntelligenceReadinessService($probe);

        $this->assertSame($service->snapshot(), $service->snapshot());
        $this->assertSame(1, $probe->calls);
    }

    public function test_async_worker_requires_a_fresh_bounded_heartbeat(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-27T02:00:00Z'));
        config([
            'queue.default' => 'database',
            'website_intelligence.readiness.worker_stale_seconds' => 90,
        ]);
        $probe = new SystemIntelligenceDependencyProbe;
        $key = config('website_intelligence.readiness.worker_heartbeat_key');

        Cache::put($key, now()->subSeconds(91)->getTimestamp(), 300);
        $this->assertSame('not_ready', $probe->check()['worker']['status']);

        Cache::put($key, now()->getTimestamp(), 300);
        $this->assertSame('ready', $probe->check()['worker']['status']);
    }
}
