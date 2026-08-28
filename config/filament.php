<?php

return [

    

    'broadcasting' => [

        'echo' => [
            'broadcaster' => 'reverb',
            'key' => env('VITE_REVERB_APP_KEY'),
            'wsHost' => env('VITE_REVERB_HOST', 'localhost'),
            'wsPort' => env('VITE_REVERB_PORT', 8080),
            'wssPort' => env('VITE_REVERB_PORT', 8080),
            'authEndpoint' => '/broadcasting/auth',
            'forceTLS' => env('VITE_REVERB_SCHEME', 'http') === 'https',
            'enabledTransports' => ['ws', 'wss'],
        ],

    ],

    

    'default_filesystem_disk' => env('FILAMENT_FILESYSTEM_DISK', 'public'),

    

    'assets_path' => null,

    

    'cache_path' => base_path('bootstrap/cache/filament'),

    

    'livewire_loading_delay' => 'default',

    

    'system_route_prefix' => 'filament',

];
