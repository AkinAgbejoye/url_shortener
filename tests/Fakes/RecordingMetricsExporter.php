<?php

namespace Tests\Fakes;

use App\Contracts\MetricsExporter;

class RecordingMetricsExporter implements MetricsExporter
{
    /** @var array<int, array{name: string, labels: array<string, string>}> */
    public array $counters = [];

    /** @var array<int, array{name: string, milliseconds: float, labels: array<string, string>}> */
    public array $timings = [];

    public function increment(string $name, array $labels = []): void
    {
        $this->counters[] = compact('name', 'labels');
    }

    public function timing(string $name, float $milliseconds, array $labels = []): void
    {
        $this->timings[] = compact('name', 'milliseconds', 'labels');
    }

    /** @param array<string, string> $labels */
    public function hasCounter(string $name, array $labels): bool
    {
        return in_array(compact('name', 'labels'), $this->counters, true);
    }

    /** @param array<string, string> $labels */
    public function hasTiming(string $name, array $labels): bool
    {
        return collect($this->timings)->contains(
            fn (array $timing): bool => $timing['name'] === $name
                && $timing['labels'] === $labels
                && $timing['milliseconds'] >= 0,
        );
    }
}
