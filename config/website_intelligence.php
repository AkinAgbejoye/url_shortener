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
        'max_active_per_account' => (int) env('INTELLIGENCE_MAX_ACTIVE_PER_ACCOUNT', 3),
        'max_active_per_origin' => (int) env('INTELLIGENCE_MAX_ACTIVE_PER_ORIGIN', 1),
        'max_active_global' => (int) env('INTELLIGENCE_MAX_ACTIVE_GLOBAL', 20),
        'max_redirects' => (int) env('INTELLIGENCE_MAX_REDIRECTS', 3),
        'max_dns_answers' => (int) env('INTELLIGENCE_MAX_DNS_ANSWERS', 8),
        'max_header_bytes' => (int) env('INTELLIGENCE_MAX_HEADER_BYTES', 16384),
        'max_compressed_bytes' => (int) env('INTELLIGENCE_MAX_COMPRESSED_BYTES', 1000000),
        'max_decompressed_bytes' => (int) env('INTELLIGENCE_MAX_DECOMPRESSED_BYTES', 5000000),
        'max_dom_nodes' => (int) env('INTELLIGENCE_MAX_DOM_NODES', 20000),
        'max_links' => (int) env('INTELLIGENCE_MAX_LINKS', 500),
        'max_text_bytes' => (int) env('INTELLIGENCE_MAX_TEXT_BYTES', 500000),
        'connect_timeout_seconds' => (float) env('INTELLIGENCE_CONNECT_TIMEOUT_SECONDS', 3),
        'request_timeout_seconds' => (float) env('INTELLIGENCE_REQUEST_TIMEOUT_SECONDS', 10),
    ],
];
