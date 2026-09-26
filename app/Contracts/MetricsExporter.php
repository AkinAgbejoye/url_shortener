<?php

namespace App\Contracts;

interface MetricsExporter
{
    /** @param array<string, string> $labels */
    public function increment(string $name, array $labels = []): void;

    /** @param array<string, string> $labels */
    public function timing(string $name, float $milliseconds, array $labels = []): void;
}
