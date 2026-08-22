<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\PennantServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    PennantServiceProvider::class,
];
