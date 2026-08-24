<?php

use App\Models\Location;
use App\Models\Plan;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

it('supports allowlisted filters and includes', function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Location::factory()->create();

    $permissions = [
        'ViewAny:Plan',
    ];

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    Sanctum::actingAs($user);

    $serviceA = Service::factory()->create(['name' => 'Service A']);
    $serviceB = Service::factory()->create(['name' => 'Service B']);

    Plan::factory()
        ->create([
            'name' => 'Plan A',
            'code' => 'A',
        ])
        ->services()->attach($serviceA->id);

    Plan::factory()
        ->create([
            'name' => 'Plan B',
            'code' => 'B',
        ])
        ->services()->attach($serviceB->id);

    $response = $this->getJson("/api/v1/plans?filter[service_id]={$serviceA->id}&include=services&sort=name")
        ->assertSuccessful()
        ->json('data');

    expect($response)->toHaveCount(1);
    expect($response[0]['name'])->toBe('Plan A');
    expect($response[0]['services'][0]['id'])->toBe($serviceA->id);
});

it('returns 400 for invalid query parameters', function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $permissions = [
        'ViewAny:Plan',
    ];

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/plans?sort=unknown')
        ->assertStatus(400)
        ->assertJsonPath('message', 'Invalid query parameters.');

    $this->getJson('/api/v1/plans?include=unknown')
        ->assertStatus(400)
        ->assertJsonPath('message', 'Invalid query parameters.');

    $this->getJson('/api/v1/plans?filter[unknown]=value')
        ->assertStatus(400)
        ->assertJsonPath('message', 'Invalid query parameters.');

    $this->getJson('/api/v1/plans?trashed=invalid')
        ->assertStatus(400)
        ->assertJsonPath('message', 'Invalid query parameters.');
});
