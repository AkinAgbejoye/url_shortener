<?php

namespace App\Metrics;

use App\Contracts\MetricsExporter;
use Illuminate\Support\Facades\Log;
use Throwable;

class SafeMetricsExporter implements MetricsExporter
{
    public function __construct(private readonly MetricsExporter $exporter) {}

    public function increment(string $name, array $labels = []): void
    {
        $this->export(fn () => $this->exporter->increment($name, $labels), $name);
    }

    public function timing(string $name, float $milliseconds, array $labels = []): void
    {
        $this->export(fn () => $this->exporter->timing($name, $milliseconds, $labels), $name);
    }

    private function export(callable $callback, string $name): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            try {
                Log::warning('metrics_export_failed', [
                    'metric' => $name,
                    'exception_class' => $exception::class,
                ]);
            } catch (Throwable) {
                // Observability failures must never alter the application response.
            }
        }
    }
}
