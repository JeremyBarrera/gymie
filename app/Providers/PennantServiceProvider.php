<?php

namespace App\Providers;

use App\Contracts\TenantContext;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;

class PennantServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Feature::resolveScopeUsing(fn (): mixed => app(TenantContext::class)->location());

        Feature::define('checkin.override', fn (): bool => true);

        Feature::define('api.checkin.lookup', fn (): bool => true);

        Feature::define('api.signup.apply', fn (): bool => true);
    }
}
