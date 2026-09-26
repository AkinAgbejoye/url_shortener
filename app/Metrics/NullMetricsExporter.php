<?php

namespace App\Metrics;

use App\Contracts\MetricsExporter;

class NullMetricsExporter implements MetricsExporter
{
    public function increment(string $name, array $labels = []): void
    {
        // Metrics are disabled.
    }

    public function timing(string $name, float $milliseconds, array $labels = []): void
    {
        // Metrics are disabled.
    }
}
