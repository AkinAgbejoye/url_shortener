<?php

return [
    'version' => '1.0.0',
    'operations' => [
        'listAnalyses' => [
            'method' => 'get',
            'path' => '/v1/analyses',
            'scope' => 'analyses:read',
            'statuses' => [200, 401, 403, 429, 500],
        ],
        'createAnalysis' => [
            'method' => 'post',
            'path' => '/v1/analyses',
            'scope' => 'analyses:write',
            'statuses' => [202, 400, 401, 403, 409, 422, 429, 500],
        ],
        'getAnalysis' => [
            'method' => 'get',
            'path' => '/v1/analyses/{analysis_id}',
            'scope' => 'analyses:read',
            'statuses' => [200, 401, 403, 404, 429, 500],
        ],
        'deleteAnalysis' => [
            'method' => 'delete',
            'path' => '/v1/analyses/{analysis_id}',
            'scope' => 'analyses:write',
            'statuses' => [202, 401, 403, 404, 409, 429, 500],
        ],
        'cancelAnalysis' => [
            'method' => 'post',
            'path' => '/v1/analyses/{analysis_id}/cancellation',
            'scope' => 'analyses:write',
            'statuses' => [202, 401, 403, 404, 409, 429, 500],
        ],
        'getAnalysisReport' => [
            'method' => 'get',
            'path' => '/v1/analyses/{analysis_id}/report',
            'scope' => 'analyses:read',
            'statuses' => [200, 401, 403, 404, 409, 410, 429, 500],
        ],
        'getIntelligenceReadiness' => [
            'method' => 'get',
            'path' => '/v1/intelligence/readiness',
            'scope' => 'operations:read',
            'statuses' => [200, 401, 403, 429, 500, 503],
        ],
    ],
];
