<?php

use App\Models\Location;
use App\Models\User;
use App\Support\Locations\LocationAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'owner']);
    Role::firstOrCreate(['name' => 'staff']);
});

it('scopes staff to the locations in their user_locations rows', function (): void {
    $staff = User::factory()->create()->assignRole('staff');
    $locationA = Location::factory()->create(['name' => 'Location A']);
    $locationB = Location::factory()->create(['name' => 'Location B']);
    $staff->locations()->attach($locationA);

    expect(LocationAccess::accessibleLocationIds($staff))
        ->toContain($locationA->id)
        ->not->toContain($locationB->id);
});

it('lets the owner access every location without rows', function (): void {
    $admin = User::factory()->create()->assignRole('owner');
    $location = Location::factory()->create();

    expect(LocationAccess::accessibleLocationIds($admin))->toBeNull();
    expect(LocationAccess::canAccess($admin, $location->id))->toBeTrue();
});

it('denies a staff member access to a location they are not assigned', function (): void {
    $staff = User::factory()->create()->assignRole('staff');
    $location = Location::factory()->create();

    expect(LocationAccess::canAccess($staff, $location->id))->toBeFalse();
});

it('limits the assignable location options to the editor jurisdiction', function (): void {
    $staff = User::factory()->create()->assignRole('staff');
    $locationA = Location::factory()->create(['name' => 'Location A']);
    $locationB = Location::factory()->create(['name' => 'Location B']);
    $staff->locations()->attach($locationA);

    $options = LocationAccess::locationOptions($staff);

    expect($options)->toHaveKey($locationA->id)
        ->not->toHaveKey($locationB->id);
});

it('prevents assigning a location outside the editor jurisdiction', function (): void {
    $staff = User::factory()->create()->assignRole('staff');
    $locationA = Location::factory()->create();
    $locationB = Location::factory()->create();
    $staff->locations()->attach($locationA);

    LocationAccess::assertAssignmentsWithinJurisdiction(
        $staff,
        [Role::where('name', 'staff')->first()->id],
        [$locationB->id]
    );
})->throws(ValidationException::class);

it('defaults the dashboard filter scope to the staff accessible locations', function (): void {
    $staff = User::factory()->create()->assignRole('staff');
    $locationA = Location::factory()->create();
    $locationB = Location::factory()->create();
    $staff->locations()->attach($locationA);

    expect(LocationAccess::scopeFromFilters($staff, []))->toBe([$locationA->id]);
});

it('lets the owner aggregate across every location from the dashboard filters', function (): void {
    $admin = User::factory()->create()->assignRole('owner');
    Location::factory()->create();
    Location::factory()->create();

    expect(LocationAccess::scopeFromFilters($admin, []))->toBeNull();
});

it('intersects an explicit dashboard filter selection with the staff jurisdiction', function (): void {
    $staff = User::factory()->create()->assignRole('staff');
    $locationA = Location::factory()->create();
    $locationB = Location::factory()->create();
    $staff->locations()->attach($locationA);

    expect(LocationAccess::scopeFromFilters($staff, ['location_ids' => [$locationA->id, $locationB->id]]))
        ->toBe([$locationA->id]);
});

it('honours an explicit dashboard filter selection for the owner', function (): void {
    $admin = User::factory()->create()->assignRole('owner');
    $locationA = Location::factory()->create();
    $locationB = Location::factory()->create();

    expect(LocationAccess::scopeFromFilters($admin, ['location_ids' => [$locationB->id]]))
        ->toBe([$locationB->id]);
});
