<?php

namespace App\Providers;

use App\Contracts\TenantContext;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;

/**
 * Registers the Pennant feature flags used across the app.
 *
 * Every feature defaults to enabled; an operator can flip any of them off
 * without a deploy.
 *
 * Pennant's default scope is the authenticated user, but these flags are
 * operator-level kill switches that must apply to everyone at once. The
 * scope is therefore pinned to the current location (tenant): each location
 * keeps its own flag state, and everything falls back to the global `null`
 * row when no location is resolved (single-tenant installs).
 */
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
