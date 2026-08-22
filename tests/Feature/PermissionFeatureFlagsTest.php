<?php

use App\Filament\Resources\Members\MemberResource;
use App\Helpers\Helpers;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    Helpers::setTestSettingsOverride(null);
});

function createPermissionFlag(string $name): Permission
{
    return Permission::findOrCreate($name, 'web');
}

function createSuperAdminUser(): User
{
    Role::findOrCreate('owner', 'web');

    return User::factory()->create()->assignRole('owner');
}

it('gives the owner role every permission regardless of flags', function (): void {
    $user = createSuperAdminUser();

    Helpers::setTestSettingsOverride([
        'permissions' => ['enabled' => false, 'disabled' => ['ViewAny:Member']],
    ]);

    expect($user->can('ViewAny:Member'))->toBeTrue();
    expect($user->can('View:Settings'))->toBeTrue();
    expect($user->can('custom-gate-ability'))->toBeTrue();
});

it('denies every permission for non-owner users when the master switch is off', function (): void {
    createPermissionFlag('ViewAny:Member');
    $user = User::factory()->create();
    $user->givePermissionTo('ViewAny:Member');

    Helpers::setTestSettingsOverride([
        'permissions' => ['enabled' => false, 'disabled' => []],
    ]);

    expect($user->can('ViewAny:Member'))->toBeFalse();
});

it('denies a flagged-off permission and allows it once re-enabled', function (): void {
    createPermissionFlag('ViewAny:Member');
    $user = User::factory()->create();
    $user->givePermissionTo('ViewAny:Member');

    Helpers::setTestSettingsOverride([
        'permissions' => ['enabled' => true, 'disabled' => ['ViewAny:Member']],
    ]);

    expect($user->can('ViewAny:Member'))->toBeFalse();

    Helpers::setTestSettingsOverride([
        'permissions' => ['enabled' => true, 'disabled' => []],
    ]);

    expect($user->can('ViewAny:Member'))->toBeTrue();
});

it('still requires the role permission when flags are enabled', function (): void {
    createPermissionFlag('ViewAny:Member');
    $user = User::factory()->create();

    Helpers::setTestSettingsOverride([
        'permissions' => ['enabled' => true, 'disabled' => []],
    ]);

    expect($user->can('ViewAny:Member'))->toBeFalse();

    $user->givePermissionTo('ViewAny:Member');

    expect($user->can('ViewAny:Member'))->toBeTrue();
});

it('does not apply feature flags to non-permission gate abilities', function (): void {
    Gate::define('manage-report', fn (User $user): bool => $user->id === 1);

    $user = User::factory()->create(['id' => 1]);

    Helpers::setTestSettingsOverride([
        'permissions' => ['enabled' => false, 'disabled' => ['ViewAny:Member']],
    ]);

    expect($user->can('manage-report'))->toBeTrue();
});

it('enforces the flag through the member policy', function (): void {
    createPermissionFlag('ViewAny:Member');
    $user = User::factory()->create();
    $user->givePermissionTo('ViewAny:Member');

    $this->actingAs($user);

    Helpers::setTestSettingsOverride([
        'permissions' => ['enabled' => true, 'disabled' => ['ViewAny:Member']],
    ]);

    expect($user->can('viewAny', Member::class))->toBeFalse();
    expect(MemberResource::canAccess())->toBeFalse();

    Helpers::setTestSettingsOverride([
        'permissions' => ['enabled' => true, 'disabled' => []],
    ]);

    expect($user->can('viewAny', Member::class))->toBeTrue();
    expect(MemberResource::canAccess())->toBeTrue();
});

it('cannot delete the owner role for any user', function (): void {
    createSuperAdminUser();

    $role = Role::findByName('owner');

    expect($role->delete())->toBeFalse();
    expect(Role::where('name', 'owner')->exists())->toBeTrue();
});

it('allows deleting non-protected roles', function (): void {
    $role = Role::findOrCreate('Manager', 'web');

    expect($role->delete())->toBeTrue();
    expect(Role::where('name', 'Manager')->exists())->toBeFalse();
});

it('denies the delete policy for the owner role', function (): void {
    createSuperAdminUser();
    $user = User::factory()->create();

    $role = Role::findByName('owner');

    expect($user->can('delete', $role))->toBeFalse();
});

it('denies the delete policy for owner actors too', function (): void {
    $superAdmin = createSuperAdminUser();

    $role = Role::findByName('owner');

    expect($superAdmin->can('delete', $role))->toBeFalse();
});

it('defaults the permissions settings to enabled with nothing disabled', function (): void {
    $settings = Helpers::getSettings();

    expect($settings['permissions']['enabled'])->toBeTrue();
    expect($settings['permissions']['disabled'])->toBe([]);
});
