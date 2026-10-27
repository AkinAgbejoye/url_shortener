<?php

$operatorEmails = array_values(array_filter(array_map(
    static fn (string $email): string => strtolower(trim($email)),
    explode(',', (string) env('INTELLIGENCE_OPERATOR_EMAILS', '')),
)));

return [
    'operators' => [
        'emails' => $operatorEmails,
    ],
    'readiness' => [
        'cache_seconds' => (int) env('INTELLIGENCE_READINESS_CACHE_SECONDS', 5),
        'check_timeout_ms' => (int) env('INTELLIGENCE_READINESS_CHECK_TIMEOUT_MS', 1000),
        'worker_stale_seconds' => (int) env('INTELLIGENCE_WORKER_STALE_SECONDS', 90),
        'worker_heartbeat_key' => 'website-intelligence:worker-heartbeat',
        'storage_disk' => env('INTELLIGENCE_STORAGE_DISK', 'local'),
    ],
    'operations' => [
        'snapshot_key' => 'website-intelligence:operations-snapshot',
        'stale_seconds' => (int) env('INTELLIGENCE_OPERATIONS_STALE_SECONDS', 120),
    ],
    'budgets' => [
        'max_concurrent_jobs' => (int) env('INTELLIGENCE_MAX_CONCURRENT_JOBS', 4),
        'max_queue_age_seconds' => (int) env('INTELLIGENCE_MAX_QUEUE_AGE_SECONDS', 300),
        'max_job_runtime_seconds' => (int) env('INTELLIGENCE_MAX_JOB_RUNTIME_SECONDS', 120),
        'max_response_bytes' => (int) env('INTELLIGENCE_MAX_RESPONSE_BYTES', 5000000),
        'retention_days' => (int) env('INTELLIGENCE_RETENTION_DAYS', 30),
    ],
];
