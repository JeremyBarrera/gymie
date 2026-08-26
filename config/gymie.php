<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public funnel host
    |--------------------------------------------------------------------------
    |
    | The internet-facing hostname served through Tailscale Funnel. Panel
    | routes answer 404 for requests arriving with this host so no admin
    | surface exists publicly; LAN and tailnet hostnames keep full access.
    |
    */

    'funnel_host' => env('FUNNEL_HOST', ''),

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

    /*
    |--------------------------------------------------------------------------
    | QR code base URL
    |--------------------------------------------------------------------------
    |
    | Absolute origin embedded in generated check-in / signup QR codes.
    | Phones scan the code on a DIFFERENT device than the server, so this
    | must be an address reachable from the gym's network (e.g. the LAN
    | IP or the public hostname) - APP_URL alone is often not. Leave blank
    | to fall back to APP_URL.
    |
    */

    'qr_base_url' => env('QR_BASE_URL'),

];
