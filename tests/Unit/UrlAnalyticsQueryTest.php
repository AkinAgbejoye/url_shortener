<?php

namespace Tests\Unit;

use App\Models\Url;
use App\Services\UrlAnalyticsQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class UrlAnalyticsQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_a_chronological_zero_filled_utc_series(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-28T23:30:00-04:00'));
        [$url, $token] = $this->managedUrl();
        foreach ([
            ['2026-09-21', 100],
            ['2026-09-22', 2],
            ['2026-09-25', 3],
            ['2026-09-29', 5],
        ] as [$date, $count]) {
            $url->dailyAnalytics()->create([
                'date' => $date,
                'redirect_count' => $count,
            ]);
        }

        $result = app(UrlAnalyticsQuery::class)->forUrl($url->short_code, $token, 8);

        $this->assertSame('8d', $result['range']);
        $this->assertSame('UTC', $result['timezone']);
        $this->assertSame('2026-09-22', $result['start_date']);
        $this->assertSame('2026-09-29', $result['end_date']);
        $this->assertSame(10, $result['total_redirects']);
        $this->assertSame([
            ['date' => '2026-09-22', 'redirect_count' => 2],
            ['date' => '2026-09-23', 'redirect_count' => 0],
            ['date' => '2026-09-24', 'redirect_count' => 0],
            ['date' => '2026-09-25', 'redirect_count' => 3],
            ['date' => '2026-09-26', 'redirect_count' => 0],
            ['date' => '2026-09-27', 'redirect_count' => 0],
            ['date' => '2026-09-28', 'redirect_count' => 0],
            ['date' => '2026-09-29', 'redirect_count' => 5],
        ], $result['series']);
    }

    public function test_it_returns_the_bounded_default_empty_range(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-28T10:00:00Z'));
        config()->set('url_shortener.analytics.max_query_days', 7);
        [$url, $token] = $this->managedUrl();

        $result = app(UrlAnalyticsQuery::class)->forUrl($url->short_code, $token, 7);

        $this->assertSame(0, $result['total_redirects']);
        $this->assertCount(7, $result['series']);
        $this->assertSame('2026-09-22', $result['start_date']);
        $this->assertSame('2026-09-28', $result['end_date']);
    }

    public function test_it_defensively_rejects_an_out_of_bounds_range(): void
    {
        config()->set('url_shortener.analytics.max_query_days', 7);
        [$url, $token] = $this->managedUrl();

        $this->expectException(InvalidArgumentException::class);

        app(UrlAnalyticsQuery::class)->forUrl($url->short_code, $token, 8);
    }

    /** @return array{Url, string} */
    private function managedUrl(): array
    {
        $token = str_repeat('a', 64);
        $url = Url::create([
            'short_code' => 'analytics-query',
            'long_url' => 'https://private.example/destination',
            'management_token_hash' => hash('sha256', $token),
        ]);

        return [$url, $token];
    }
}
