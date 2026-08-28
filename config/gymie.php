<?php

return [

    

    'funnel_host' => env('FUNNEL_HOST', ''),

    

    'owner' => [
        'name' => env('OWNER_NAME', 'Test User'),
        'email' => env('OWNER_EMAIL', 'test@example.com'),
        'password' => env('OWNER_PASSWORD', 'test'),
    ],

    

    'qr_base_url' => env('QR_BASE_URL'),

];
