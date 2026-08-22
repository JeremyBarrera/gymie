<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tenancy
    |--------------------------------------------------------------------------
    |
    | When enabled, the app runs shared-database multi-tenant mode: every
| business record belongs to a location, all queries are scoped to the current
| location, and scheduled maintenance commands run once per location.
    |
    */

    'enabled' => env('GYMIE_TENANCY_ENABLED', true),

];
