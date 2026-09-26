<?php

namespace App\Support;

use App\Contracts\MetricsExporter;
use InvalidArgumentException;

class OperationalMetrics
{
    private const CACHE_OPERATIONS = ['lock', 'read', 'write'];

    private const CACHE_OUTCOMES = ['failure', 'hit', 'miss', 'success'];

    private const REQUEST_OPERATIONS = ['create', 'redirect'];

    private const REQUEST_OUTCOMES = ['conflict', 'created', 'error', 'found', 'not_found', 'replayed'];

    public function __construct(private readonly MetricsExporter $exporter) {}

    public function request(string $operation, string $outcome, float $milliseconds): void
    {
        $this->ensureAllowed($operation, self::REQUEST_OPERATIONS, 'request operation');
        $this->ensureAllowed($outcome, self::REQUEST_OUTCOMES, 'request outcome');

        $labels = ['operation' => $operation, 'outcome' => $outcome];
        $this->exporter->increment('requests_total', $labels);
        $this->exporter->timing('request_duration_ms', max(0, $milliseconds), $labels);
    }

    public function cache(string $operation, string $outcome): void
    {
        $this->ensureAllowed($operation, self::CACHE_OPERATIONS, 'cache operation');
        $this->ensureAllowed($outcome, self::CACHE_OUTCOMES, 'cache outcome');

        $this->exporter->increment('cache_operations_total', [
            'operation' => $operation,
            'outcome' => $outcome,
        ]);
    }

    /** @param array<int, string> $allowed */
    private function ensureAllowed(string $value, array $allowed, string $label): void
    {
        if (! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException("Unsupported {$label}.");
        }
    }
}
