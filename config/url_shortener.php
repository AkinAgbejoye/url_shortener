<?php

return [
    'max_lifetime_days' => (int) env('URL_MAX_LIFETIME_DAYS', 365),

    'cleanup' => [
        'batch_size' => (int) env('URL_CLEANUP_BATCH_SIZE', 100),
        'retention_days' => (int) env('URL_CLEANUP_RETENTION_DAYS', 30),
        'time' => env('URL_CLEANUP_TIME', '02:30'),
    ],
];
