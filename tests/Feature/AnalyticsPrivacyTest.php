<?php

namespace Tests\Feature;

use App\Contracts\MetricsExporter;
use App\Models\Url;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Fakes\RecordingMetricsExporter;
use Tests\TestCase;

class AnalyticsPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_analytics_telemetry_and_payloads_exclude_link_and_visitor_data(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-30T12:00:00Z'));
        $token = str_repeat('a1', 32);
        $shortCode = 'private-campaign';
        $destination = 'https://private.example/path?customer=secret';
        $visitorValues = [
            '203.0.113.77',
            'analytics-private-agent',
            'https://referrer.example/private-campaign',
        ];
        Url::create([
            'short_code' => $shortCode,
            'long_url' => $destination,
            'management_token_hash' => hash('sha256', $token),
        ]);
        $exporter = new RecordingMetricsExporter;
        $this->app->instance(MetricsExporter::class, $exporter);
        Log::spy();

        $this->withHeaders([
            'Referer' => $visitorValues[2],
            'User-Agent' => $visitorValues[1],
            'X-Forwarded-For' => $visitorValues[0],
        ])->get("/{$shortCode}")->assertRedirect($destination);

        Log::shouldHaveReceived('info')
            ->once()
            ->with('analytics_recorded', [
                'outcome' => 'recorded',
                'bucket_date' => '2026-09-30',
            ]);
        $this->assertSame([
            'name' => 'analytics_redirects_total',
            'labels' => ['outcome' => 'recorded'],
        ], collect($exporter->counters)->firstWhere('name', 'analytics_redirects_total'));

        $response = $this->withHeader('X-Management-Token', $token)
            ->getJson("/api/v1/urls/{$shortCode}/analytics?range=1d")
            ->assertOk()
            ->assertExactJson([
                'range' => '1d',
                'timezone' => 'UTC',
                'start_date' => '2026-09-30',
                'end_date' => '2026-09-30',
                'total_redirects' => 1,
                'series' => [
                    ['date' => '2026-09-30', 'redirect_count' => 1],
                ],
            ]);

        $analyticsSurfaces = json_encode([
            'metrics' => $exporter->counters,
            'timings' => $exporter->timings,
            'payload' => $response->json(),
        ], JSON_THROW_ON_ERROR);

        foreach ([$shortCode, $destination, $token, ...$visitorValues] as $forbidden) {
            $this->assertStringNotContainsString((string) $forbidden, $analyticsSurfaces);
        }
    }
}
