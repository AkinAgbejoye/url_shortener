<?php

use App\Support\SentryEventSanitizer;
use App\Support\SentryIntegrationFilter;

return [
    'dsn' => env('SENTRY_LARAVEL_DSN'),
    'environment' => env('SENTRY_ENVIRONMENT'),
    'release' => env('SENTRY_RELEASE'),
    'sample_rate' => (float) env('SENTRY_SAMPLE_RATE', 1.0),
    'send_default_pii' => false,
    'max_request_body_size' => 'never',
    'enable_tracing' => false,
    'enable_logs' => false,
    'enable_metrics' => false,
    'default_integrations' => false,
    'integrations' => SentryIntegrationFilter::class.'::filter',
    'before_send' => [SentryEventSanitizer::class, 'sanitize'],

    'breadcrumbs' => [
        'logs' => false,
        'cache' => false,
        'livewire' => false,
        'sql_queries' => false,
        'sql_bindings' => false,
        'queue_info' => false,
        'command_info' => false,
        'http_client_requests' => false,
        'notifications' => false,
    ],

    'tracing' => [
        'default_integrations' => false,
        'queue_job_transactions' => false,
        'queue_jobs' => false,
        'sql_queries' => false,
        'sql_bindings' => false,
        'views' => false,
        'http_client_requests' => false,
        'cache' => false,
        'redis_commands' => false,
        'notifications' => false,
        'missing_routes' => false,
        'continue_after_response' => false,
    ],
];
