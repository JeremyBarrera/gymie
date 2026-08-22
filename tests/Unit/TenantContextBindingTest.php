<?php

use App\Contracts\TenantContext;
use App\Providers\AppServiceProvider;
use App\Services\LocationTenantContext;
use Illuminate\Foundation\Application;
use ReflectionProperty;

beforeEach(function (): void {
    foreach (['pinnedLocationId' => null, 'resolvedDefaultLocationId' => false, 'defaultLocationId' => null] as $property => $value) {
        $reflection = new ReflectionProperty(LocationTenantContext::class, $property);
        $reflection->setValue(null, $value);
    }
});

it('registers a singleton location tenant context for shared-database installations', function (): void {
    $application = new Application;

    (new AppServiceProvider($application))->register();

    $tenantContext = $application->make(TenantContext::class);

    expect($tenantContext)
        ->toBeInstanceOf(LocationTenantContext::class)
        ->and($tenantContext->locationId())->toBeNull()
        ->and($application->make(TenantContext::class))->toBe($tenantContext);
});

it('preserves a tenant context registered by an add-on', function (): void {
    $application = new Application;
    $tenantContext = new class implements TenantContext
    {
        public function locationId(): ?int
        {
            return 42;
        }
    };

    $application->singleton(TenantContext::class, fn (): TenantContext => $tenantContext);

    (new AppServiceProvider($application))->register();

    expect($application->make(TenantContext::class))
        ->toBe($tenantContext)
        ->and($application->make(TenantContext::class)->locationId())->toBe(42);
});
