<?php

return [
    

    'api_path' => 'api/v1',

    

    'api_domain' => null,

    

    'info' => [
        'version' => env('API_VERSION', '1.0.0'),
        'title' => 'Gymie API',
        'description' => 'Gymie JSON API. Bearer token auth: send `Authorization: Bearer <token>` and `Accept: application/json`.',
    ],

    

    'servers' => null,

    

    'middleware' => [
        'web',
        'Dedoc\\Scramble\\Http\\Middleware\\RestrictedDocsAccess',
    ],

    

    'extensions' => [],
];
