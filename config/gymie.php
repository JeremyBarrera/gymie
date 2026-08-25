<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Owner account
    |--------------------------------------------------------------------------
    |
    | Credentials used by the UserSeeder to bootstrap the top-level owner
    | account (`test@example.com` / `test` by default). Override them in your
    | `.env` to change the default admin login.
    |
    */

    'owner' => [
        'name' => env('OWNER_NAME', 'Test User'),
        'email' => env('OWNER_EMAIL', 'test@example.com'),
        'password' => env('OWNER_PASSWORD', 'test'),
    ],

];
