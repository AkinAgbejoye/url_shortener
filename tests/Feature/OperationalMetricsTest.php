<?php

namespace Tests\Feature;

use App\Contracts\MetricsExporter;
use App\Metrics\SafeMetricsExporter;
use App\Models\Url;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\Fakes\RecordingMetricsExporter;
use Tests\TestCase;

class OperationalMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_creation_and_cached_redirect_emit_request_and_cache_metrics(): void
    {
        $metrics = $this->recordMetrics();

        $this->postJson('/api/v1/urls', ['long_url' => 'https://example.com/private-path'])
            ->assertCreated();
        $this->get('/1')->assertRedirect('https://example.com/private-path');

        $this->assertTrue($metrics->hasCounter('cache_operations_total', [
            'operation' => 'write',
            'outcome' => 'success',
        ]));
        $this->assertTrue($metrics->hasCounter('cache_operations_total', [
            'operation' => 'read',
            'outcome' => 'hit',
        ]));
        $this->assertTrue($metrics->hasCounter('requests_total', [
            'operation' => 'create',
            'outcome' => 'created',
        ]));
        $this->assertTrue($metrics->hasTiming('request_duration_ms', [
            'operation' => 'redirect',
            'outcome' => 'found',
        ]));

        foreach ([...$metrics->counters, ...$metrics->timings] as $metric) {
            $this->assertSame(['operation', 'outcome'], array_keys($metric['labels']));
            $this->assertNotContains('https://example.com/private-path', $metric['labels']);
            $this->assertNotContains('1', $metric['labels']);
        }
    }

    public function test_database_fallback_and_missing_redirect_emit_cache_miss_outcomes(): void
    {
        $metrics = $this->recordMetrics();
        Url::create(['short_code' => 'database', 'long_url' => 'https://database.example']);
        Cache::forget('url:database');

        $this->get('/database')->assertRedirect('https://database.example');
        $this->get('/missing')->assertNotFound();

        $this->assertTrue($metrics->hasCounter('cache_operations_total', [
            'operation' => 'read',
            'outcome' => 'miss',
        ]));
        $this->assertTrue($metrics->hasCounter('requests_total', [
            'operation' => 'redirect',
            'outcome' => 'not_found',
        ]));
    }

    public function test_creation_replay_and_conflict_outcomes_are_distinct(): void
    {
        $metrics = $this->recordMetrics();
        $headers = ['Idempotency-Key' => 'metrics-request'];

        $this->postJson('/api/v1/urls', ['long_url' => 'https://first.example'], $headers)
            ->assertCreated();
        $this->postJson('/api/v1/urls', ['long_url' => 'https://first.example'], $headers)
            ->assertOk();
        $this->postJson('/api/v1/urls', ['long_url' => 'https://second.example'], $headers)
            ->assertConflict();

        foreach (['created', 'replayed', 'conflict'] as $outcome) {
            $this->assertTrue($metrics->hasCounter('requests_total', [
                'operation' => 'create',
                'outcome' => $outcome,
            ]));
        }
    }

    public function test_cache_failures_emit_a_bounded_failure_outcome(): void
    {
        $metrics = $this->recordMetrics();
        Url::create(['short_code' => 'fallback', 'long_url' => 'https://fallback.example']);
        Cache::shouldReceive('get')
            ->once()
            ->with('url:fallback')
            ->andThrow(new RuntimeException('Cache unavailable'));

        $this->get('/fallback')->assertRedirect('https://fallback.example');

        $this->assertTrue($metrics->hasCounter('cache_operations_total', [
            'operation' => 'read',
            'outcome' => 'failure',
        ]));
    }

    public function test_exporter_failures_do_not_change_the_application_response(): void
    {
        $failingExporter = new class implements MetricsExporter
        {
            public function increment(string $name, array $labels = []): void
            {
                throw new RuntimeException('Exporter unavailable');
            }

            public function timing(string $name, float $milliseconds, array $labels = []): void
            {
                throw new RuntimeException('Exporter unavailable');
            }
        };
        $this->app->instance(MetricsExporter::class, new SafeMetricsExporter($failingExporter));

        $this->postJson('/api/v1/urls', ['long_url' => 'https://example.com'])
            ->assertCreated();
        $this->get('/1')->assertRedirect('https://example.com');
    }

    private function recordMetrics(): RecordingMetricsExporter
    {
        $exporter = new RecordingMetricsExporter;
        $this->app->instance(MetricsExporter::class, $exporter);

        return $exporter;
    }
}
