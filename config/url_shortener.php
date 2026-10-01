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

    'api_keys' => [
        'max_active_per_user' => (int) env('API_KEY_MAX_ACTIVE_PER_USER', 10),
        'name_max_length' => (int) env('API_KEY_NAME_MAX_LENGTH', 80),
        'max_expiration_days' => (int) env('API_KEY_MAX_EXPIRATION_DAYS', 365),
        'per_page' => (int) env('API_KEY_PER_PAGE', 10),
        'max_per_page' => (int) env('API_KEY_MAX_PER_PAGE', 25),
        'create_per_minute' => (int) env('API_KEY_CREATE_PER_MINUTE', 5),
        'revoke_per_minute' => (int) env('API_KEY_REVOKE_PER_MINUTE', 10),
        'list_per_minute' => (int) env('API_KEY_LIST_PER_MINUTE', 30),
        'requests_per_minute' => (int) env('API_KEY_REQUESTS_PER_MINUTE', 60),
        'account_requests_per_minute' => (int) env('API_KEY_ACCOUNT_REQUESTS_PER_MINUTE', 120),
        'invalid_requests_per_minute' => (int) env('API_KEY_INVALID_REQUESTS_PER_MINUTE', 10),
        'anonymous_create_per_minute' => (int) env('API_ANONYMOUS_CREATE_PER_MINUTE', 10),
        'anonymous_manage_per_minute' => (int) env('API_ANONYMOUS_MANAGE_PER_MINUTE', 30),
        'last_used_update_seconds' => (int) env('API_KEY_LAST_USED_UPDATE_SECONDS', 300),
        'scopes' => [
            'urls:read' => 'Read owned link metadata',
            'urls:write' => 'Create and manage owned links',
            'analytics:read' => 'Read owned link analytics',
        ],
    ],
];
