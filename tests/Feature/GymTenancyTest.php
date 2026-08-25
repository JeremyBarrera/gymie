<?php

use App\Contracts\TenantContext;
use App\Filament\Resources\Locations\LocationResource;
use App\Models\Location;
use App\Models\LocationToken;
use App\Models\Member;
use App\Models\Plan;
use App\Models\QueueEntry;
use App\Models\Subscription;
use App\Models\User;
use App\Services\LocationTenantContext;
use App\Support\Locations\LocationAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('owner', 'web');
    Feature::flushCache();
    LocationTenantContext::setLocationId(null);
});

afterEach(function (): void {
    LocationTenantContext::setLocationId(null);
});

function createSecondLocation(): Location
{
    return Location::query()->create(['name' => 'Location B']);
}

it('falls back to the default location when no tenant is resolved', function (): void {
    $location = Location::query()->create(['name' => 'Main Location']);

    expect((int) $location->id)->toBe(1)
        ->and(app(TenantContext::class)->locationId())->toBe(1)
        ->and(Location::query()->count())->toBe(1);
});

it('scopes location staff to their own locations', function (): void {
    $locationB = createSecondLocation();
    $main = Location::query()->create(['name' => 'Main Location']);

    $staffB = User::factory()->create();
    $staffB->locations()->sync([$locationB->id]);
    $staffA = User::factory()->create();
    $staffA->locations()->sync([$main->id]);

    $planB = Plan::factory()->create(['name' => 'B Plan', 'location_id' => $locationB->id]);
    $planA = Plan::factory()->create(['name' => 'A Plan', 'location_id' => $main->id]);

    $this->actingAs($staffB);
    $memberB = Member::query()->create(['name' => 'B Member']);
    Subscription::factory()->create(['member_id' => $memberB->id, 'plan_id' => $planB->id]);

    $this->actingAs($staffA);
    $memberA = Member::query()->create(['name' => 'A Member']);
    Subscription::factory()->create(['member_id' => $memberA->id, 'plan_id' => $planA->id]);

    $this->actingAs($staffB);

    expect(Member::query()->count())->toBe(1)
        ->and(Member::query()->first()->name)->toBe('B Member');

    $this->actingAs($staffA);

    expect(Member::query()->count())->toBe(1)
        ->and(Member::query()->first()->name)->toBe('A Member');
});

it('lets the owner operate across every location', function (): void {
    createSecondLocation();

    Location::query()->create(['name' => 'Main Location']);

    $owner = User::factory()->create()->assignRole('owner');

    $this->actingAs($owner);

    expect(Location::query()->count())->toBe(2)
        ->and(LocationAccess::canAccessEveryLocation($owner))->toBeTrue();

    $staff = User::factory()->create();

    expect(LocationAccess::canAccessEveryLocation($staff))->toBeFalse();
});

it('scopes queue entries to the location of the submitting staff', function (): void {
    $locationB = createSecondLocation();
    $main = Location::query()->create(['name' => 'Main Location']);

    $staffB = User::factory()->create();
    $staffB->locations()->sync([$locationB->id]);
    $staffA = User::factory()->create();
    $staffA->locations()->sync([$main->id]);

    $this->actingAs($staffB);
    QueueEntry::query()->create([
        'uuid' => Str::uuid()->toString(),
        'kind' => 'checkin',
        'status' => 'waiting',
        'payload' => [],
    ]);

    $this->actingAs($staffA);

    expect(QueueEntry::query()->count())->toBe(0);

    $this->actingAs($staffB);

    expect(QueueEntry::query()->count())->toBe(1);
});

it('resolves the tenant from the scan token for unauthenticated visitors', function (): void {
    $location = createSecondLocation();
    $token = LocationToken::query()->create([
        'token' => Str::random(40),
        'tokenable_type' => Location::class,
        'tokenable_id' => $location->id,
        'kind' => 'checkin',
    ]);

    $this->get('/checkin/'.$token->token)->assertOk();

    expect(app(TenantContext::class)->locationId())->toBe((int) $location->id);

    $this->post('/checkin/submit', [
        'kind' => 'checkin',
        'identifier_type' => 'code',
        'value' => 'MEM-001',
        'token' => $token->token,
    ]);

    expect(app(TenantContext::class)->locationId())->toBe((int) $location->id);
});

it('keeps feature flags per location', function (): void {
    createSecondLocation();

    LocationTenantContext::setLocationId(1);
    Feature::deactivate('api.checkin.lookup');
    Feature::flushCache();

    LocationTenantContext::setLocationId(2);

    expect(Feature::active('api.checkin.lookup'))->toBeTrue();

    LocationTenantContext::setLocationId(1);

    expect(Feature::active('api.checkin.lookup'))->toBeFalse();
});

it('rejects API login for users without an accessible location', function (): void {
    $userB = User::factory()->create(['password' => bcrypt('secret')]);

    $location = createSecondLocation();

    $userA = User::factory()->create(['password' => bcrypt('secret')]);
    $userA->locations()->sync([$location->id]);

    $this->postJson('/api/v1/auth/login', [
        'email' => $userB->email,
        'password' => 'secret',
        'device_name' => 'test',
    ])->assertStatus(422);

    $this->postJson('/api/v1/auth/login', [
        'email' => $userA->email,
        'password' => 'secret',
        'device_name' => 'test',
    ])->assertStatus(200)->assertJsonStructure(['token']);
});

it('protects the owner role from deletion', function (): void {
    $role = Role::findByName('owner');

    expect($role->delete())->toBeFalse()
        ->and(Role::query()->where('name', 'owner')->exists())->toBeTrue();
});

it('exposes the locations resource to location staff', function (): void {
    $location = Location::query()->create(['name' => 'Main Location']);
    $owner = User::factory()->create()->assignRole('owner');

    $permission = Permission::findOrCreate('ViewAny:Location', 'web');
    $staffRole = Role::findOrCreate('location-staff', 'web');
    $staffRole->givePermissionTo($permission);

    $staff = User::factory()->create();
    $staff->assignRole($staffRole);
    $staff->locations()->sync([$location->id]);

    $this->actingAs($staff);

    expect(LocationResource::canViewAny())->toBeTrue();

    $this->actingAs($owner);

    expect(LocationResource::canViewAny())->toBeTrue();
});
