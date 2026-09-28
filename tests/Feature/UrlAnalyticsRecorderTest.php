<?php

namespace Tests\Feature;

use App\Contracts\MetricsExporter;
use App\Models\Url;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Fakes\RecordingMetricsExporter;
use Tests\TestCase;

class UrlAnalyticsRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_redirects_increment_one_daily_bucket(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-27T23:59:00Z'));
        $url = Url::create(['short_code' => 'daily', 'long_url' => 'https://daily.example']);

        $this->get('/daily')->assertRedirect('https://daily.example');
        $this->get('/daily')->assertRedirect('https://daily.example');

        $this->assertDatabaseHas('url_analytics_daily', [
            'url_id' => $url->id,
            'date' => '2026-09-27',
            'redirect_count' => 2,
        ]);
        $this->assertDatabaseCount('url_analytics_daily', 1);
    }

    public function test_midnight_utc_creates_the_next_bucket(): void
    {
        $url = Url::create(['short_code' => 'midnight', 'long_url' => 'https://midnight.example']);

        $this->travelTo(CarbonImmutable::parse('2026-09-27T23:59:59Z'));
        $this->get('/midnight')->assertRedirect('https://midnight.example');

        $this->travelTo(CarbonImmutable::parse('2026-09-28T00:00:00Z'));
        $this->get('/midnight')->assertRedirect('https://midnight.example');

        $this->assertDatabaseHas('url_analytics_daily', [
            'url_id' => $url->id,
            'date' => '2026-09-27',
            'redirect_count' => 1,
        ]);
        $this->assertDatabaseHas('url_analytics_daily', [
            'url_id' => $url->id,
            'date' => '2026-09-28',
            'redirect_count' => 1,
        ]);
    }

    public function test_existing_bucket_increment_uses_an_atomic_update(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-27T12:00:00Z'));
        $url = Url::create(['short_code' => 'atomic', 'long_url' => 'https://atomic.example']);
        DB::table('url_analytics_daily')->insert([
            'url_id' => $url->id,
            'date' => '2026-09-27',
            'redirect_count' => 41,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get('/atomic')->assertRedirect('https://atomic.example');

        $this->assertDatabaseHas('url_analytics_daily', [
            'url_id' => $url->id,
            'date' => '2026-09-27',
            'redirect_count' => 42,
        ]);
    }

    public function test_it_does_not_count_excluded_redirect_outcomes_or_api_requests(): void
    {
        Url::create(['short_code' => 'expired', 'long_url' => 'https://expired.example', 'expires_at' => now()->subMinute()]);
        Url::create(['short_code' => 'disabled', 'long_url' => 'https://disabled.example', 'disabled_at' => now()]);
        $deleted = Url::create(['short_code' => 'deleted', 'long_url' => 'https://deleted.example']);
        $deleted->delete();

        $this->get('/missing')->assertNotFound();
        $this->get('/expired')->assertNotFound();
        $this->get('/disabled')->assertNotFound();
        $this->get('/deleted')->assertNotFound();
        $this->postJson('/api/v1/urls', ['long_url' => 'https://created.example'])->assertCreated();

        $this->assertDatabaseCount('url_analytics_daily', 0);
    }

    public function test_analytics_database_failures_do_not_break_redirects_and_use_bounded_telemetry(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-27T12:00:00Z'));
        $exporter = new RecordingMetricsExporter;
        $this->app->instance(MetricsExporter::class, $exporter);
        Cache::put('url:isolated', [
            'version' => 2,
            'url_id' => 999_999,
            'long_url' => 'https://isolated.example',
            'expires_at' => null,
        ], now()->addHour());
        Log::spy();

        $this->get('/isolated')->assertRedirect('https://isolated.example');

        $this->assertTrue($exporter->hasCounter('analytics_redirects_total', [
            'outcome' => 'failed',
        ]));
        Log::shouldHaveReceived('warning')
            ->once()
            ->with('analytics_record_failed', \Mockery::on(
                fn (array $context): bool => $context === [
                    'outcome' => 'failed',
                    'bucket_date' => '2026-09-27',
                    'exception_class' => 'Illuminate\Database\QueryException',
                ]
            ));
    }

    public function test_successful_analytics_telemetry_is_bounded(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-27T12:00:00Z'));
        $exporter = new RecordingMetricsExporter;
        $this->app->instance(MetricsExporter::class, $exporter);
        Url::create(['short_code' => 'bounded', 'long_url' => 'https://bounded.example']);
        Log::spy();

        $this->get('/bounded')->assertRedirect('https://bounded.example');

        $this->assertTrue($exporter->hasCounter('analytics_redirects_total', [
            'outcome' => 'recorded',
        ]));
        Log::shouldHaveReceived('info')
            ->once()
            ->with('analytics_recorded', [
                'outcome' => 'recorded',
                'bucket_date' => '2026-09-27',
            ]);
    }
}
