<?php

namespace Tests\Unit;

use App\Contracts\MetricsExporter;
use App\Metrics\SafeMetricsExporter;
use App\Metrics\StatsdMetricsExporter;
use App\Support\OperationalMetrics;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Tests\Fakes\RecordingMetricsExporter;
use Tests\TestCase;

class OperationalMetricsTest extends TestCase
{
    public function test_it_emits_bounded_request_and_cache_metrics(): void
    {
        $exporter = new RecordingMetricsExporter;
        $metrics = new OperationalMetrics($exporter);

        $metrics->request('create', 'created', 12.5);
        $metrics->cache('read', 'hit');

        $this->assertTrue($exporter->hasCounter('requests_total', [
            'operation' => 'create',
            'outcome' => 'created',
        ]));
        $this->assertTrue($exporter->hasTiming('request_duration_ms', [
            'operation' => 'create',
            'outcome' => 'created',
        ]));
        $this->assertTrue($exporter->hasCounter('cache_operations_total', [
            'operation' => 'read',
            'outcome' => 'hit',
        ]));
    }

    public function test_it_rejects_unbounded_label_values(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new OperationalMetrics(new RecordingMetricsExporter))
            ->request('create', 'https://private.example/path', 1);
    }

    public function test_the_safe_exporter_isolates_transport_failures(): void
    {
        Log::spy();
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
        $metrics = new OperationalMetrics(new SafeMetricsExporter($failingExporter));

        $metrics->request('redirect', 'found', 2);

        Log::shouldHaveReceived('warning')
            ->twice()
            ->with('metrics_export_failed', \Mockery::on(
                fn (array $context): bool => in_array(
                    $context['metric'],
                    ['requests_total', 'request_duration_ms'],
                    true,
                ) && $context['exception_class'] === RuntimeException::class,
            ));
    }

    public function test_the_statsd_exporter_formats_counters_timings_and_sorted_tags(): void
    {
        $payloads = [];
        $exporter = new StatsdMetricsExporter(
            host: '127.0.0.1',
            port: 8125,
            prefix: 'url shortener',
            timeout: 0.2,
            sender: function (string $payload) use (&$payloads): void {
                $payloads[] = $payload;
            },
        );

        $exporter->increment('requests_total', ['outcome' => 'created', 'operation' => 'create']);
        $exporter->timing('request_duration_ms', 12.3456, ['operation' => 'create']);

        $this->assertSame([
            'url_shortener.requests_total:1|c|#operation:create,outcome:created',
            'url_shortener.request_duration_ms:12.346|ms|#operation:create',
        ], $payloads);
    }
}
