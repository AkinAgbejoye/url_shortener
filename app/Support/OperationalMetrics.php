<?php

namespace App\Support;

use App\Contracts\MetricsExporter;
use InvalidArgumentException;

class OperationalMetrics
{
    private const CACHE_OPERATIONS = ['lock', 'read', 'write'];

    private const CACHE_OUTCOMES = ['failure', 'hit', 'miss', 'success'];

    private const CLEANUP_RECORD_TYPES = ['idempotency_key', 'url'];

    private const CLEANUP_OUTCOMES = ['deleted', 'examined', 'failed', 'skipped'];

    private const ALIAS_TYPES = ['custom', 'generated'];

    private const ALIAS_OUTCOMES = ['claimed', 'conflict', 'exhausted', 'retry'];

    private const ANALYTICS_OUTCOMES = ['failed', 'recorded'];

    private const ANALYTICS_CLEANUP_SCOPES = ['batch', 'run'];

    private const ANALYTICS_CLEANUP_OUTCOMES = ['deleted', 'failed', 'skipped', 'succeeded'];

    private const API_KEY_AUTHENTICATION_OUTCOMES = [
        'expired',
        'invalid',
        'malformed',
        'missing',
        'revoked',
        'unknown',
        'valid',
    ];

    private const REQUEST_OPERATIONS = ['create', 'redirect'];

    private const REQUEST_OUTCOMES = [
        'conflict',
        'created',
        'deleted',
        'disabled',
        'error',
        'expired',
        'found',
        'not_found',
        'replayed',
    ];

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

    public function cleanup(string $recordType, string $outcome): void
    {
        $this->ensureAllowed($recordType, self::CLEANUP_RECORD_TYPES, 'cleanup record type');
        $this->ensureAllowed($outcome, self::CLEANUP_OUTCOMES, 'cleanup outcome');

        $this->exporter->increment('lifecycle_cleanup_total', [
            'record_type' => $recordType,
            'outcome' => $outcome,
        ]);
    }

    public function aliasAllocation(string $type, string $outcome): void
    {
        $this->ensureAllowed($type, self::ALIAS_TYPES, 'alias type');
        $this->ensureAllowed($outcome, self::ALIAS_OUTCOMES, 'alias outcome');

        $this->exporter->increment('alias_allocations_total', [
            'type' => $type,
            'outcome' => $outcome,
        ]);
    }

    public function analytics(string $outcome): void
    {
        $this->ensureAllowed($outcome, self::ANALYTICS_OUTCOMES, 'analytics outcome');

        $this->exporter->increment('analytics_redirects_total', [
            'outcome' => $outcome,
        ]);
    }

    public function analyticsCleanup(string $scope, string $outcome): void
    {
        $this->ensureAllowed($scope, self::ANALYTICS_CLEANUP_SCOPES, 'analytics cleanup scope');
        $this->ensureAllowed($outcome, self::ANALYTICS_CLEANUP_OUTCOMES, 'analytics cleanup outcome');

        $this->exporter->increment('analytics_cleanup_total', [
            'scope' => $scope,
            'outcome' => $outcome,
        ]);
    }

    public function apiKeyAuthentication(string $outcome): void
    {
        $this->ensureAllowed(
            $outcome,
            self::API_KEY_AUTHENTICATION_OUTCOMES,
            'API key authentication outcome',
        );

        $this->exporter->increment('api_key_authentication_total', [
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
