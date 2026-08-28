<?php

use App\Enums\Status;
use App\Events\MemberBanChanged;
use App\Filament\Resources\Members\Pages\ListMembers;
use App\Filament\Resources\Members\Pages\ViewMember;
use App\Filament\Resources\Members\Tables\MemberTable;
use App\Models\Location;
use App\Models\LocationToken;
use App\Models\Member;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'owner']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Location::factory()->has(LocationToken::factory()->checkin()->count(1), 'tokens')->create();
});

function m3Staff(): User
{
    $staff = User::factory()->create();
    $staff->assignRole('owner');

    return $staff;
}

function m3LocationToken(): string
{
    return (string) LocationToken::query()->where('kind', 'checkin')->value('token');
}

it('bans a member with an optional reason from the table action', function (): void {
    Event::fake([MemberBanChanged::class]);
    $member = Member::factory()->create(['status' => Status::Active]);
    $this->actingAs(m3Staff());

    Livewire::test(ListMembers::class)
        ->callAction(
            TestAction::make('ban')->table($member),
            data: ['reason' => 'Fights at the front desk'],
        )
        ->assertHasNoActionErrors();

    expect($member->refresh()->status)->toBe(Status::Banned)
        ->and($member->ban_reason)->toBe('Fights at the front desk');

    Event::assertDispatched(MemberBanChanged::class, fn (MemberBanChanged $event): bool => m3ReachesLocationChannel($event));
});

function m3ReachesLocationChannel(MemberBanChanged $event): bool
{
    return collect($event->broadcastOn())->contains(
        fn (PrivateChannel $channel): bool => $channel->name === 'private-location.'.m3LocationToken(),
    );
}

it('bans without a reason and stores null', function (): void {
    $member = Member::factory()->create(['status' => Status::Inactive]);
    $this->actingAs(m3Staff());

    Livewire::test(ListMembers::class)
        ->callAction(TestAction::make('ban')->table($member))
        ->assertHasNoActionErrors();

    expect($member->refresh()->status)->toBe(Status::Banned)
        ->and($member->ban_reason)->toBeNull();
});

it('lifts a ban, clears the reason and restores check-in eligibility', function (): void {
    Event::fake([MemberBanChanged::class]);
    $member = Member::factory()->create(['status' => Status::Banned, 'ban_reason' => 'Fights']);
    $this->actingAs(m3Staff());

    Livewire::test(ListMembers::class)
        ->callAction(TestAction::make('unban')->table($member))
        ->assertHasNoActionErrors();

    expect($member->refresh()->status)->toBe(Status::Active)
        ->and($member->ban_reason)->toBeNull()
        ->and($member->checkInBlocker())->toBeNull();

    Event::assertDispatched(MemberBanChanged::class, fn (MemberBanChanged $event): bool => m3ReachesLocationChannel($event));
});

it('hides the ban action from staff without the permission and refuses crafted calls', function (): void {
    $member = Member::factory()->create(['status' => Status::Active]);

    Permission::findOrCreate('Ban:Member', 'web');
    $this->actingAs(User::factory()->create());

    $action = MemberTable::banAction()->record($member);

    expect($action->isAuthorized())->toBeFalse();

    $component = Livewire::test(ListMembers::class);

    
    try {
        $component->callAction(TestAction::make('ban')->table($member));
        $this->fail('Expected the hidden action call to be refused.');
    } catch (Throwable) {
    }

    
    try {
        $action->call([]);
        $this->fail('Expected the crafted ban call to be refused.');
    } catch (AuthorizationException) {
    }

    expect($member->refresh()->status)->toBe(Status::Active);
});

it('grants the ban action to staff holding the Ban:Member permission', function (): void {
    $member = Member::factory()->create(['status' => Status::Active]);

    Permission::findOrCreate('Ban:Member', 'web');
    $staff = User::factory()->create();
    $staff->givePermissionTo('Ban:Member');
    $this->actingAs($staff);

    expect(MemberTable::banAction()->record($member)->isAuthorized())->toBeTrue()
        ->and(MemberTable::unbanAction()->record($member)->isAuthorized())->toBeTrue();
});

it('fires the ban broadcast on every location channel with a safe payload', function (): void {
    Event::fake([MemberBanChanged::class]);

    Location::factory()->has(LocationToken::factory()->checkin()->count(1), 'tokens')->create();
    $member = Member::factory()->create(['status' => Status::Active]);
    $this->actingAs(m3Staff());

    Livewire::test(ListMembers::class)
        ->callAction(TestAction::make('ban')->table($member));

    Event::assertDispatched(MemberBanChanged::class, function (MemberBanChanged $event) use ($member): bool {
        return $event->memberId === (int) $member->id
            && $event->banned === true
            && count($event->locationTokens) === 2;
    });
});

it('shows the unban action on the member view page header', function (): void {
    $member = Member::factory()->create(['status' => Status::Banned, 'ban_reason' => 'Fights']);
    $this->actingAs(m3Staff());

    Livewire::test(ViewMember::class, ['record' => $member->id])
        ->callAction('unban')
        ->assertSuccessful();

    expect($member->refresh()->status)->toBe(Status::Active)
        ->and($member->ban_reason)->toBeNull();
});

it('bans from the member view page header with a reason', function (): void {
    $member = Member::factory()->create(['status' => Status::Active]);
    $this->actingAs(m3Staff());

    Livewire::test(ViewMember::class, ['record' => $member->id])
        ->callAction('ban', data: ['reason' => 'Chargeback fraud'])
        ->assertSuccessful();

    expect($member->refresh()->status)->toBe(Status::Banned)
        ->and($member->ban_reason)->toBe('Chargeback fraud');
});

it('bans members in bulk with a shared reason and skips already-banned rows', function (): void {
    $first = Member::factory()->create(['status' => Status::Active]);
    $alreadyBanned = Member::factory()->create(['status' => Status::Banned, 'ban_reason' => 'Original']);
    $this->actingAs(m3Staff());

    Livewire::test(ListMembers::class)
        ->set('selectedTableRecords', [$first->getKey(), $alreadyBanned->getKey()])
        ->callAction(TestAction::make('ban')->table()->bulk(), data: ['reason' => 'Shared reason'])
        ->assertHasNoActionErrors();

    expect($first->refresh()->status)->toBe(Status::Banned)
        ->and($first->ban_reason)->toBe('Shared reason')
        ->and($alreadyBanned->refresh()->ban_reason)->toBe('Original');
});
