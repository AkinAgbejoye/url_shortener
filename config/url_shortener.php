<?php

return [
    'max_lifetime_days' => (int) env('URL_MAX_LIFETIME_DAYS', 365),

    'aliases' => [
        'allocation_attempts' => (int) env('URL_ALIAS_ALLOCATION_ATTEMPTS', 20),
        'min_length' => (int) env('URL_ALIAS_MIN_LENGTH', 3),
        'max_length' => (int) env('URL_ALIAS_MAX_LENGTH', 48),
        'reserved' => [
            'admin',
            'api',
            'assets',
            'build',
            'dashboard',
            'health',
            'login',
            'logout',
            'register',
            'status',
            'storage',
            'up',
        ],
    ],

    'analytics' => [
        'max_query_days' => (int) env('URL_ANALYTICS_MAX_QUERY_DAYS', 90),
        'retention_days' => (int) env('URL_ANALYTICS_RETENTION_DAYS', 365),
        'cleanup' => [
            'batch_size' => (int) env('URL_ANALYTICS_CLEANUP_BATCH_SIZE', 500),
            'time' => env('URL_ANALYTICS_CLEANUP_TIME', '03:15'),
        ],
    ],

    'cleanup' => [
        'batch_size' => (int) env('URL_CLEANUP_BATCH_SIZE', 100),
        'retention_days' => (int) env('URL_CLEANUP_RETENTION_DAYS', 30),
        'time' => env('URL_CLEANUP_TIME', '02:30'),
    ],
];
