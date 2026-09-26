<?php

return [
    'driver' => env('METRICS_DRIVER', 'null'),
    'prefix' => env('METRICS_PREFIX', 'url_shortener'),

    'statsd' => [
        'host' => env('METRICS_STATSD_HOST', '127.0.0.1'),
        'port' => (int) env('METRICS_STATSD_PORT', 8125),
        'timeout' => (float) env('METRICS_STATSD_TIMEOUT', 0.2),
    ],
];
