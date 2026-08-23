<?php

use App\Enums\Status;
use App\Filament\Pages\Reception;
use App\Models\Location;
use App\Models\Member;
use App\Models\Plan;
use App\Models\PlanCheckIn;
use App\Models\QueueEntry;
use App\Models\Service;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'owner']);
});

function manualStaff(): User
{
    $staff = User::factory()->create();
    $staff->assignRole('owner');

    return $staff;
}

function manualPlan(): Plan
{
    $service = Service::factory()->create();

    return Plan::factory()->create([
        'service_id' => $service->id,
        'amount' => 100,
        'track_uses' => false,
        'status' => Status::Active,
    ]);
}

function manualMember(array $overrides = []): Member
{
    return Member::factory()->create(array_merge([
        'status' => Status::Active,
        'contact' => '5559876543',
        'government_id' => 'GOV123456',
    ], $overrides));
}

function manualSubscription(Member $member, Plan $plan): Subscription
{
    return Subscription::factory()->create([
        'member_id' => $member->id,
        'plan_id' => $plan->id,
        'status' => Status::Ongoing,
        'start_date' => now()->subDays(5)->toDateString(),
        'end_date' => now()->addDays(25)->toDateString(),
    ]);
}

it('opens the shared overlay for a single walk-up match and records the PlanCheckIn', function (): void {
    $plan = manualPlan();
    $member = manualMember(['name' => 'Zara Walkup']);
    $subscription = manualSubscription($member, $plan);

    Livewire::actingAs(manualStaff())
        ->test(Reception::class)
        ->set('manualCheckInSearch', 'Zara')
        ->call('openManualCheckInOverlay')
        ->assertSet('showCheckInOverlay', true)
        ->assertSet('checkInManualMode', true)
        ->assertSet('selectedCheckInEntryId', null)
        ->assertSet('selectedCheckInMemberId', $member->id)
        ->assertSet('checkInServiceId', (int) $plan->service_id)
        ->call('approveCheckIn')
        ->assertDispatched('notify')
        ->assertSet('showCheckInOverlay', false)
        ->assertSet('checkInManualMode', false)
        ->assertSet('manualCheckInSearch', '');

    expect(PlanCheckIn::where('subscription_id', $subscription->id)->exists())->toBeTrue()
        ->and(QueueEntry::count())->toBe(0);
});

it('shows the candidate picker inside the same overlay when several members match', function (): void {
    $plan = manualPlan();
    $first = manualMember(['name' => 'Ada Twin']);
    $second = manualMember(['name' => 'Ben Twin']);
    $firstSubscription = manualSubscription($first, $plan);
    $secondSubscription = manualSubscription($second, $plan);

    Livewire::actingAs(manualStaff())
        ->test(Reception::class)
        ->set('manualCheckInSearch', '5559876543')
        ->call('openManualCheckInOverlay')
        ->assertSet('showCheckInOverlay', true)
        ->assertSet('checkInManualMode', true)
        ->assertSet('selectedCheckInMemberId', null)
        ->assertSet('manualCheckInCandidates', fn (array $ids): bool => collect($ids)->sort()->values()->all() === [$first->id, $second->id])
        ->call('selectCheckInMember', $second->id)
        ->assertSet('selectedCheckInMemberId', $second->id)
        ->set('checkInServiceId', (int) $plan->service_id)
        ->call('approveCheckIn')
        ->assertDispatched('notify')
        ->assertSet('showCheckInOverlay', false);

    expect(PlanCheckIn::where('subscription_id', $secondSubscription->id)->exists())->toBeTrue()
        ->and(PlanCheckIn::where('subscription_id', $firstSubscription->id)->exists())->toBeFalse()
        ->and(QueueEntry::count())->toBe(0);
});

it('rejects picking a walk-up candidate outside the search results', function (): void {
    manualPlan();
    $member = manualMember();
    $stranger = manualMember(['contact' => '5550000000']);

    Livewire::actingAs(manualStaff())
        ->test(Reception::class)
        ->set('manualCheckInSearch', (string) $member->code)
        ->call('openManualCheckInOverlay')
        ->assertSet('selectedCheckInMemberId', $member->id)
        ->call('selectCheckInMember', $stranger->id)
        ->assertSet('selectedCheckInMemberId', $member->id);
});

it('notifies when no active member matches the walk-up search', function (): void {
    Livewire::actingAs(manualStaff())
        ->test(Reception::class)
        ->set('manualCheckInSearch', 'nobody-here')
        ->call('openManualCheckInOverlay')
        ->assertDispatched('notify', type: 'danger', message: __('app.reception.manual_no_match', ['search' => 'nobody-here']))
        ->assertSet('showCheckInOverlay', false)
        ->assertSet('checkInManualMode', false);
});

it('warns when every walk-up match is inactive', function (): void {
    manualMember(['name' => 'Sleepy Inactive', 'status' => Status::Inactive]);

    Livewire::actingAs(manualStaff())
        ->test(Reception::class)
        ->set('manualCheckInSearch', 'Sleepy')
        ->call('openManualCheckInOverlay')
        ->assertDispatched('notify', type: 'warning', message: __('app.reception.check_in_member_inactive'))
        ->assertSet('showCheckInOverlay', false);
});

it('denies a walk-up check-in without touching any queue entry', function (): void {
    manualPlan();
    manualMember();

    Livewire::actingAs(manualStaff())
        ->test(Reception::class)
        ->set('manualCheckInSearch', '5559876543')
        ->call('openManualCheckInOverlay')
        ->assertSet('showCheckInOverlay', true)
        ->call('denyCheckIn')
        ->assertSet('checkInDenyStep', true)
        ->set('checkInDenyReason', 'Not a member here')
        ->call('confirmDenyCheckIn')
        ->assertDispatched('notify')
        ->assertSet('showCheckInOverlay', false);

    expect(PlanCheckIn::count())->toBe(0)
        ->and(QueueEntry::count())->toBe(0);
});

it('overrides an ineligible walk-up service and records the override reason', function (): void {
    \Laravel\Pennant\Feature::activate('checkin.override');

    $plan = manualPlan();
    $member = manualMember();
    $entry = null;

    Livewire::actingAs(manualStaff())
        ->test(Reception::class)
        ->set('manualCheckInSearch', (string) $member->government_id)
        ->call('openManualCheckInOverlay')
        ->assertSet('showCheckInOverlay', true)
        ->assertSet('selectedCheckInMemberId', $member->id)
        ->set('checkInServiceId', (int) $plan->service_id)
        ->call('openCheckInOverrideFor', (int) $plan->service_id)
        ->assertSet('checkInOverrideStep', true)
        ->call('confirmCheckInOverride')
        ->assertDispatched('notify')
        ->assertSet('showCheckInOverlay', false);

    $checkIn = PlanCheckIn::first();
    expect($checkIn)->not->toBeNull()
        ->and($checkIn->override)->toBeTrue()
        ->and($checkIn->service_id)->toBe((int) $plan->service_id)
        ->and(QueueEntry::count())->toBe(0);
});

it('finds members by name, code, government ID and unprefixed phone variants', function (): void {
    manualPlan();
    $member = manualMember(['contact' => '555 123 4567']);
    $prefixed = manualMember(['name' => 'Prefix Member', 'contact' => '+15559876111']);

    \App\Helpers\Helpers::setTestSettingsOverride([
        'general' => ['country' => 'United States'],
    ]);

    expect(Member::searchByIdentifier($member->name)->pluck('id'))->toContain($member->id)
        ->and(Member::searchByIdentifier((string) $member->code)->pluck('id'))->toContain($member->id)
        ->and(Member::searchByIdentifier('GOV123456')->pluck('id'))->toContain($member->id)
        ->and(Member::searchByIdentifier('555 123')->pluck('id'))->toContain($member->id)
        ->and(Member::searchByIdentifier('5559876111')->pluck('id'))->toContain($prefixed->id)
        ->and(Member::searchByIdentifier('no-such-member')->isEmpty())->toBeTrue();

    \App\Helpers\Helpers::setTestSettingsOverride(null);
});

it('populates the debounced live results when the search term updates', function (): void {
    $member = manualMember(['name' => 'Nora Live']);

    Livewire::actingAs(manualStaff())
        ->test(Reception::class)
        ->set('manualCheckInSearch', 'Nora')
        ->assertSet('manualSearchResults', fn (array $rows): bool => collect($rows)->pluck('id')->all() === [$member->id])
        ->set('manualCheckInSearch', '')
        ->assertSet('manualSearchResults', []);
});

it('finds the same member by every identifier type in the live results', function (): void {
    $member = manualMember([
        'name' => 'Iris Finder',
        'contact' => '5559876543',
        'government_id' => 'GOV-FINDER',
    ]);

    Livewire::actingAs(manualStaff())
        ->test(Reception::class)
        ->set('manualCheckInSearch', 'Iris Finder')
        ->assertSet('manualSearchResults', fn (array $rows): bool => collect($rows)->pluck('id')->all() === [$member->id])
        ->set('manualCheckInSearch', (string) $member->code)
        ->assertSet('manualSearchResults', fn (array $rows): bool => collect($rows)->pluck('id')->all() === [$member->id])
        ->set('manualCheckInSearch', '5559876')
        ->assertSet('manualSearchResults', fn (array $rows): bool => collect($rows)->pluck('id')->all() === [$member->id])
        ->set('manualCheckInSearch', 'GOV-FINDER')
        ->assertSet('manualSearchResults', fn (array $rows): bool => collect($rows)->pluck('id')->all() === [$member->id]);
});

it('shows the empty state for terms without live matches', function (): void {
    manualMember();

    Livewire::actingAs(manualStaff())
        ->test(Reception::class)
        ->set('manualCheckInSearch', 'nobody-here')
        ->assertSet('manualSearchResults', [])
        ->assertSee(__('app.check_in.search_no_results'))
        ->assertSee(__('app.check_in.search_searching'));
});

it('keeps the live results scoped to the staff member\'s accessible locations', function (): void {
    $locationA = Location::factory()->create();
    $locationB = Location::factory()->create();

    $planA = Plan::factory()->create(['location_id' => $locationA->id]);
    $planB = Plan::factory()->create(['location_id' => $locationB->id]);

    $localMember = manualMember(['name' => 'Twin Scoped']);
    Subscription::factory()->create(['member_id' => $localMember->id, 'plan_id' => $planA->id]);

    $foreignMember = manualMember(['name' => 'Twin Scoped', 'contact' => '5559999999', 'government_id' => 'GOV-FOREIGN']);
    Subscription::factory()->create(['member_id' => $foreignMember->id, 'plan_id' => $planB->id]);

    $staff = User::factory()->create();
    $staff->locations()->sync([$locationA->id]);

    Livewire::actingAs($staff)
        ->test(Reception::class)
        ->set('manualCheckInSearch', 'Twin Scoped')
        ->assertSet('manualSearchResults', fn (array $rows): bool => collect($rows)->pluck('id')->all() === [$localMember->id])
        ->call('openManualCheckInForMember', $foreignMember->id)
        ->assertSet('showCheckInOverlay', false);
});

it('opens the shared overlay when a live result is selected', function (): void {
    $plan = manualPlan();
    $member = manualMember(['name' => 'Uma Select']);
    manualSubscription($member, $plan);

    Livewire::actingAs(manualStaff())
        ->test(Reception::class)
        ->set('manualCheckInSearch', 'Uma')
        ->call('openManualCheckInForMember', $member->id)
        ->assertSet('showCheckInOverlay', true)
        ->assertSet('checkInManualMode', true)
        ->assertSet('selectedCheckInEntryId', null)
        ->assertSet('selectedCheckInMemberId', $member->id)
        ->assertSet('checkInServiceId', (int) $plan->service_id);

    expect(PlanCheckIn::count())->toBe(0);
});

it('resolves the picked candidate when a live term matches several members', function (): void {
    $plan = manualPlan();
    $first = manualMember(['name' => 'Pia Multi']);
    $second = manualMember(['name' => 'Pia Multi Two']);
    $secondSubscription = manualSubscription($second, $plan);

    Livewire::actingAs(manualStaff())
        ->test(Reception::class)
        ->set('manualCheckInSearch', 'Pia')
        ->assertSet('manualSearchResults', fn (array $rows): bool => collect($rows)->pluck('id')->sort()->values()->all() === [$first->id, $second->id])
        ->call('openManualCheckInForMember', $second->id)
        ->assertSet('showCheckInOverlay', true)
        ->assertSet('selectedCheckInMemberId', $second->id)
        ->assertSet('checkInServiceId', (int) $plan->service_id)
        ->call('approveCheckIn')
        ->assertDispatched('notify')
        ->assertSet('showCheckInOverlay', false)
        ->assertSet('manualCheckInSearch', '');

    expect(PlanCheckIn::where('subscription_id', $secondSubscription->id)->exists())->toBeTrue()
        ->and(QueueEntry::count())->toBe(0);
});

it('ignores selections that are not part of the current live results', function (): void {
    $plan = manualPlan();
    $member = manualMember(['name' => 'Vera Guard']);
    manualSubscription($member, $plan);

    Livewire::actingAs(manualStaff())
        ->test(Reception::class)
        ->set('manualCheckInSearch', '')
        ->call('openManualCheckInForMember', $member->id)
        ->assertSet('showCheckInOverlay', false)
        ->assertSet('checkInManualMode', false)
        ->assertSet('selectedCheckInMemberId', null);
});

it('removes the legacy two-select form and sign-in action from the component and DOM', function (): void {
    $reflection = new ReflectionClass(Reception::class);

    expect($reflection->hasProperty('data'))->toBeFalse()
        ->and($reflection->hasMethod('performCheckIn'))->toBeFalse()
        ->and($reflection->hasMethod('wouldDuplicateToday'))->toBeFalse();

    $this->actingAs(manualStaff())
        ->get('/reception')
        ->assertOk()
        ->assertDontSee(__('app.placeholders.select_member'))
        ->assertDontSee(__('app.placeholders.select_plan'))
        ->assertDontSee(__('app.check_in.section_sign_in'))
        ->assertDontSee(__('app.actions.sign_in'))
        ->assertSee(__('app.reception.manual_search_placeholder'));
});
