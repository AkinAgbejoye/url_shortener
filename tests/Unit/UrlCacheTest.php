<?php

namespace Tests\Unit;

use App\Services\UrlCache;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class UrlCacheTest extends TestCase
{
    public function test_it_reads_a_valid_versioned_entry(): void
    {
        Cache::put('url:valid', [
            'version' => 2,
            'url_id' => 123,
            'long_url' => 'https://valid.example',
            'expires_at' => null,
        ], now()->addHour());

        $this->assertSame([
            'url_id' => 123,
            'long_url' => 'https://valid.example',
        ], app(UrlCache::class)->get('valid'));
    }

    public function test_it_discards_legacy_unsafe_and_expired_entries(): void
    {
        $entries = [
            'legacy' => 'https://legacy.example',
            'unsafe' => ['version' => 2, 'url_id' => 123, 'long_url' => 'javascript:alert(1)', 'expires_at' => null],
            'missing-id' => ['version' => 2, 'long_url' => 'https://missing-id.example', 'expires_at' => null],
            'expired' => [
                'version' => 2,
                'url_id' => 123,
                'long_url' => 'https://expired.example',
                'expires_at' => CarbonImmutable::now()->subSecond()->toIso8601String(),
            ],
        ];

        foreach ($entries as $shortCode => $entry) {
            Cache::put("url:{$shortCode}", $entry, now()->addHour());

            $this->assertNull(app(UrlCache::class)->get($shortCode));
            $this->assertNull(Cache::get("url:{$shortCode}"));
        }
    }

    public function test_cache_ttl_is_bounded_by_url_expiration(): void
    {
        $now = CarbonImmutable::parse('2026-09-27T06:00:00Z');
        CarbonImmutable::setTestNow($now);
        $expiration = $now->addMinutes(10);

        Cache::shouldReceive('put')
            ->once()
            ->with(
                'url:bounded',
                [
                    'version' => 2,
                    'url_id' => 123,
                    'long_url' => 'https://bounded.example',
                    'expires_at' => $expiration->toIso8601String(),
                ],
                \Mockery::on(fn (CarbonImmutable $ttl): bool => $ttl->equalTo($expiration)),
            );

        app(UrlCache::class)->put('bounded', 123, 'https://bounded.example', $expiration);

        CarbonImmutable::setTestNow();
    }

    public function test_an_expired_url_is_invalidated_instead_of_cached(): void
    {
        Cache::shouldReceive('forget')->once()->with('url:expired');
        Cache::shouldNotReceive('put');

        app(UrlCache::class)->put(
            'expired',
            123,
            'https://expired.example',
            CarbonImmutable::now()->subSecond(),
        );
    }

    public function test_it_explicitly_invalidates_a_lifecycle_cache_entry(): void
    {
        Cache::shouldReceive('forget')->once()->with('url:disabled');

        app(UrlCache::class)->forget('disabled');
    }
}
