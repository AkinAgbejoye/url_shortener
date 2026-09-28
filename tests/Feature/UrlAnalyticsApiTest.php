<?php

namespace Tests\Feature;

use App\Models\Url;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class UrlAnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authorized_owner_can_request_a_bounded_aggregate_range(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-28T10:00:00Z'));
        [$url, $token] = $this->managedUrl();
        $url->dailyAnalytics()->create(['date' => '2026-09-26', 'redirect_count' => 4]);

        $response = $this->asManager($token)
            ->getJson("/api/v1/urls/{$url->short_code}/analytics?range=3d")
            ->assertOk()
            ->assertExactJson([
                'range' => '3d',
                'timezone' => 'UTC',
                'start_date' => '2026-09-26',
                'end_date' => '2026-09-28',
                'total_redirects' => 4,
                'series' => [
                    ['date' => '2026-09-26', 'redirect_count' => 4],
                    ['date' => '2026-09-27', 'redirect_count' => 0],
                    ['date' => '2026-09-28', 'redirect_count' => 0],
                ],
            ]);

        $payload = $response->getContent();
        $this->assertStringNotContainsString($url->short_code, $payload);
        $this->assertStringNotContainsString($url->long_url, $payload);
        $this->assertStringNotContainsString($token, $payload);
        $this->assertArrayNotHasKey('url_id', $response->json());
    }

    public function test_an_empty_request_defaults_to_a_zero_filled_thirty_day_range(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-28T10:00:00Z'));
        [$url, $token] = $this->managedUrl();

        $this->asManager($token)
            ->getJson("/api/v1/urls/{$url->short_code}/analytics")
            ->assertOk()
            ->assertJsonPath('range', '30d')
            ->assertJsonPath('start_date', '2026-08-30')
            ->assertJsonPath('end_date', '2026-09-28')
            ->assertJsonPath('total_redirects', 0)
            ->assertJsonCount(30, 'series');
    }

    public function test_invalid_ranges_return_stable_validation_errors(): void
    {
        config()->set('url_shortener.analytics.max_query_days', 90);
        [$url, $token] = $this->managedUrl();

        foreach ([
            ['0d', 'The analytics range must use a positive number of days, such as 7d.'],
            ['7', 'The analytics range must use a positive number of days, such as 7d.'],
            ['91d', 'The analytics range may not exceed 90 days.'],
        ] as [$range, $message]) {
            $this->asManager($token)
                ->getJson("/api/v1/urls/{$url->short_code}/analytics?range={$range}")
                ->assertUnprocessable()
                ->assertJsonPath('message', $message)
                ->assertJsonPath('errors.range.0', $message);
        }
    }

    public function test_missing_invalid_unknown_and_deleted_credentials_share_the_same_response(): void
    {
        config()->set('app.debug', false);
        [$url, $token] = $this->managedUrl();
        $deletedToken = str_repeat('c', 64);
        $deleted = Url::create([
            'short_code' => 'deleted-analytics',
            'long_url' => 'https://deleted.example',
            'management_token_hash' => hash('sha256', $deletedToken),
        ]);
        $deleted->delete();

        $responses = [
            $this->getJson("/api/v1/urls/{$url->short_code}/analytics"),
            $this->asManager('invalid')->getJson("/api/v1/urls/{$url->short_code}/analytics"),
            $this->asManager(str_repeat('b', 64))->getJson("/api/v1/urls/{$url->short_code}/analytics"),
            $this->asManager($token)->getJson('/api/v1/urls/unknown/analytics'),
            $this->asManager($deletedToken)->getJson('/api/v1/urls/deleted-analytics/analytics'),
        ];

        foreach ($responses as $response) {
            $response->assertNotFound();
            $this->assertSame($responses[0]->json(), $response->json());
        }
    }

    public function test_analytics_requests_use_the_management_rate_limit(): void
    {
        Cache::clear();
        [$url, $token] = $this->managedUrl();

        foreach (range(1, 30) as $attempt) {
            $this->asManager($token)
                ->getJson("/api/v1/urls/{$url->short_code}/analytics?range=1d")
                ->assertOk();
        }

        $this->asManager($token)
            ->getJson("/api/v1/urls/{$url->short_code}/analytics?range=1d")
            ->assertTooManyRequests();
    }

    /** @return array{Url, string} */
    private function managedUrl(): array
    {
        Cache::clear();
        $token = str_repeat('a', 64);
        $url = Url::create([
            'short_code' => 'analytics-api',
            'long_url' => 'https://private.example/sensitive-destination',
            'management_token_hash' => hash('sha256', $token),
        ]);

        return [$url, $token];
    }

    private function asManager(string $token): static
    {
        return $this->withHeader('X-Management-Token', $token);
    }
}
