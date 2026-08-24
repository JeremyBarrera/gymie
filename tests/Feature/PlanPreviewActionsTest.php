<?php

use App\Filament\Resources\Plans\Pages\ListPlans;
use App\Filament\Resources\Plans\Tables\PlanTable;
use App\Models\Plan;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Actions\ViewAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('offers edit and status toggles in the plan preview popup footer', function (): void {
    Permission::findOrCreate('ViewAny:Plan', 'web');
    $user = User::factory()->create();
    $user->givePermissionTo('ViewAny:Plan');
    $this->actingAs($user);

    $view = Livewire::test(ListPlans::class)->instance()->getTable()->getAction('view');

    $extras = $view?->getExtraModalFooterActions();

    expect($view)->toBeInstanceOf(ViewAction::class)
        ->and($extras)->toHaveCount(3)
        ->and($extras['edit'])->toBeInstanceOf(EditAction::class)
        ->and($extras['mark_as_active']->getName())->toBe('mark_as_active')
        ->and($extras['mark_as_inactive']->getName())->toBe('mark_as_inactive');
});

it('denies marking a plan inactive without plan update permission', function (): void {
    $plan = Plan::factory()->create(['status' => 'active']);
    $this->actingAs(User::factory()->create());

    $action = PlanTable::markAsInactiveAction()->record($plan);

    expect($action->isAuthorized())->toBeFalse();
});

it('allows marking a plan inactive with plan update permission', function (): void {
    $plan = Plan::factory()->create(['status' => 'active']);
    Permission::findOrCreate('Update:Plan', 'web');
    $user = User::factory()->create();
    $user->givePermissionTo('Update:Plan');
    $this->actingAs($user);

    $action = PlanTable::markAsInactiveAction()->record($plan);

    expect($action->isAuthorized())->toBeTrue();
});

it('marks a plan inactive from the preview popup', function (): void {
    foreach (['ViewAny:Plan', 'View:Plan', 'Update:Plan'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $user = User::factory()->create();
    $user->givePermissionTo(['ViewAny:Plan', 'View:Plan', 'Update:Plan']);
    $this->actingAs($user);

    $plan = Plan::factory()->create(['status' => 'active']);

    Livewire::test(ListPlans::class)
        ->callAction([
            TestAction::make('view')->table($plan),
            TestAction::make('mark_as_inactive'),
        ])
        ->assertHasNoActionErrors();

    expect($plan->refresh()->status->value)->toBe('inactive');
});

it('refuses the inactive toggle server-side without plan update permission', function (): void {
    foreach (['ViewAny:Plan', 'View:Plan'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $user = User::factory()->create();
    $user->givePermissionTo(['ViewAny:Plan', 'View:Plan']);
    $this->actingAs($user);

    $plan = Plan::factory()->create(['status' => 'active']);

    $component = Livewire::test(ListPlans::class)
        ->call('mountAction', 'view', [], ['recordKey' => (string) $plan->getKey()])
        ->call('mountAction', 'mark_as_inactive');

    try {
        $component->call('callMountedAction');
    } catch (AuthorizationException) {
    }

    expect($plan->refresh()->status->value)->toBe('active');
});
