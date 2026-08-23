<?php

use App\Enums\Status;
use App\Filament\Livewire\LiveSignupPopup;
use App\Filament\Pages\Reception;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\LocationToken;
use App\Models\Member;
use App\Models\Plan;
use App\Models\PlanCheckIn;
use App\Models\QueueEntry;
use App\Models\Service;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'owner']);
});

function liveCheckInStaff(): User
{
    $staff = User::factory()->create();
    $staff->assignRole('owner');

    return $staff;
}

function liveCheckInPlan(): Plan
{
    $service = Service::factory()->create();

    return Plan::factory()->create([
        'service_id' => $service->id,
        'amount' => 100,
        'track_uses' => false,
        'status' => Status::Active,
    ]);
}

function liveCheckInMember(array $overrides = []): Member
{
    return Member::factory()->create(array_merge([
        'status' => Status::Active,
        'contact' => '5559876543',
    ], $overrides));
}

function liveCheckInSubscription(Member $member, Plan $plan, array $overrides = []): Subscription
{
    return Subscription::factory()->create(array_merge([
        'member_id' => $member->id,
        'plan_id' => $plan->id,
        'status' => Status::Ongoing,
        'start_date' => now()->subDays(5)->toDateString(),
        'end_date' => now()->addDays(25)->toDateString(),
    ], $overrides));
}

function liveCheckInEntry(Location $location, array $payload): QueueEntry
{
    return QueueEntry::create([
        'uuid' => (string) Str::uuid(),
        'location_id' => $location->id,
        'kind' => 'checkin',
        'payload' => $payload,
        'identifier_type' => 'contact',
        'status' => 'waiting',
        'expires_at' => now()->addMinutes(10),
    ]);
}

function liveCheckInSignupEntry(Location $location, array $overrides = []): QueueEntry
{
    return QueueEntry::create(array_merge([
        'uuid' => (string) Str::uuid(),
        'location_id' => $location->id,
        'kind' => 'signup',
        'payload' => [
            'name' => 'Jane Doe',
            'contact' => '5559876543',
            'government_id' => 'GOV999888',
            'email' => 'jane@example.com',
            'gender' => 'female',
            'dob' => '1995-05-10',
            'emergency_contact' => null,
            'health_issue' => null,
            'goal' => 'Lose weight',
            'location_id' => $location->id,
        ],
        'identifier_type' => 'contact',
        'status' => 'waiting',
        'expires_at' => now()->addMinutes(10),
    ], $overrides));
}

function liveCheckInPhoto(): string
{
    return 'data:image/png;base64,'.base64_encode('tiny-png-bytes');
}

function liveCheckInSale(Plan $plan, array $overrides = []): array
{
    return array_merge([
        'plan_id' => $plan->id,
        'start_date' => now()->toDateString(),
        'end_date' => now()->addMonth()->toDateString(),
        'payment_method' => 'cash',
        'discount_amount' => 0,
        'paid_amount' => 0,
    ], $overrides);
}

it('auto-opens the checkin overlay when a checkin entry arrives live', function (): void {
    $location = Location::factory()->create();
    $member = liveCheckInMember();
    $entry = liveCheckInEntry($location, ['candidate_member_ids' => [$member->id]]);

    Livewire::actingAs(liveCheckInStaff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->assertSet('showCheckInOverlay', true)
        ->assertSet('selectedCheckInEntryId', $entry->id)
        ->assertSet('selectedCheckInMemberId', $member->id);
});

it('deletes a signup queue entry and removes it from the list', function (): void {
    $location = Location::factory()->create();
    $entry = liveCheckInSignupEntry($location);

    $component = Livewire::actingAs(liveCheckInStaff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id]);

    expect(collect($component->instance()->signupEntries)->pluck('id'))->toContain($entry->id);

    $component->call('deleteQueueEntry', $entry->id)
        ->assertDispatched('notify');

    expect(QueueEntry::find($entry->id))->toBeNull()
        ->and(collect($component->instance()->signupEntries)->pluck('id'))->not->toContain($entry->id);
});

it('does not auto-open the checkin overlay for signup entries', function (): void {
    $location = Location::factory()->create();
    $entry = QueueEntry::create([
        'uuid' => (string) Str::uuid(),
        'location_id' => $location->id,
        'kind' => 'signup',
        'payload' => ['name' => 'Jane Doe', 'contact' => '5559876543'],
        'identifier_type' => 'contact',
        'status' => 'waiting',
        'expires_at' => now()->addMinutes(10),
    ]);

    Livewire::actingAs(liveCheckInStaff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->assertSet('showCheckInOverlay', false);
});

it('prepends live checkin entries to the checkin queue list', function (): void {
    $location = Location::factory()->create();
    $member = liveCheckInMember();
    $entry = liveCheckInEntry($location, ['candidate_member_ids' => [$member->id]]);

    Livewire::actingAs(liveCheckInStaff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->assertSet('checkinEntries', fn (array $entries): bool => collect($entries)->pluck('id')->all() === [$entry->id]);
});

it('queues checkin entries arriving while the overlay is already open', function (): void {
    $location = Location::factory()->create();
    $firstMember = liveCheckInMember();
    $secondMember = liveCheckInMember(['contact' => '5551112222']);
    $first = liveCheckInEntry($location, ['candidate_member_ids' => [$firstMember->id]]);
    $second = liveCheckInEntry($location, ['candidate_member_ids' => [$secondMember->id]]);

    Livewire::actingAs(liveCheckInStaff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $first->id])
        ->assertSet('showCheckInOverlay', true)
        ->assertSet('selectedCheckInEntryId', $first->id)
        ->call('onQueueEntryCreated', ['queueEntryId' => $second->id])
        ->assertSet('checkInPopupQueue', [$second->id])
        ->assertSet('selectedCheckInEntryId', $first->id)
        ->call('closeCheckInOverlay')
        ->assertSet('showCheckInOverlay', true)
        ->assertSet('selectedCheckInEntryId', $second->id)
        ->assertSet('selectedCheckInMemberId', $secondMember->id)
        ->assertSet('checkInPopupQueue', []);
});

it('approves a check-in from the live overlay and records the PlanCheckIn', function (): void {
    $location = Location::factory()->create();
    $plan = liveCheckInPlan();
    $member = liveCheckInMember();
    $subscription = liveCheckInSubscription($member, $plan);
    $entry = liveCheckInEntry($location, ['candidate_member_ids' => [$member->id]]);

    Livewire::actingAs(liveCheckInStaff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->set('checkInServiceId', $plan->service_id)
        ->call('approveCheckIn')
        ->assertDispatched('notify')
        ->assertSet('showCheckInOverlay', false)
        ->assertSet('selectedCheckInEntryId', null)
        ->assertSet('checkinEntries', []);

    expect($entry->refresh()->status)->toBe('approved')
        ->and(PlanCheckIn::where('subscription_id', $subscription->id)->exists())->toBeTrue();
});

it('denies a check-in from the live overlay with a reason', function (): void {
    $location = Location::factory()->create();
    $member = liveCheckInMember();
    $entry = liveCheckInEntry($location, ['candidate_member_ids' => [$member->id]]);

    Livewire::actingAs(liveCheckInStaff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->call('denyCheckIn')
        ->assertSet('checkInDenyStep', true)
        ->set('checkInDenyReason', 'Wrong person')
        ->call('confirmDenyCheckIn')
        ->assertDispatched('notify')
        ->assertSet('showCheckInOverlay', false);

    expect($entry->refresh()->status)->toBe('denied')
        ->and($entry->denied_reason)->toBe('Wrong person');
});

it('denies a check-in with a default reason when none is provided', function (): void {
    $location = Location::factory()->create();
    $member = liveCheckInMember();
    $entry = liveCheckInEntry($location, ['candidate_member_ids' => [$member->id]]);

    Livewire::actingAs(liveCheckInStaff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->call('denyCheckIn')
        ->call('confirmDenyCheckIn')
        ->assertDispatched('notify');

    expect($entry->refresh()->status)->toBe('denied')
        ->and($entry->denied_reason)->toBe(__('app.reception.denied_no_reason'));
});

it('shows the candidate picker for ambiguous identifiers and selects the right member', function (): void {
    $location = Location::factory()->create();
    $plan = liveCheckInPlan();
    $first = liveCheckInMember();
    $second = liveCheckInMember(['contact' => '5559876543']);
    $firstSubscription = liveCheckInSubscription($first, $plan);
    $secondSubscription = liveCheckInSubscription($second, $plan);
    $entry = liveCheckInEntry($location, [
        'candidate_member_ids' => [$first->id, $second->id],
    ]);

    Livewire::actingAs(liveCheckInStaff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->assertSet('showCheckInOverlay', true)
        ->assertSet('selectedCheckInMemberId', null)
        ->call('selectCheckInMember', $second->id)
        ->assertSet('selectedCheckInMemberId', $second->id)
        ->set('checkInServiceId', $plan->service_id)
        ->call('approveCheckIn')
        ->assertDispatched('notify')
        ->assertSet('showCheckInOverlay', false);

    expect(PlanCheckIn::where('subscription_id', $secondSubscription->id)->exists())->toBeTrue()
        ->and(PlanCheckIn::where('subscription_id', $firstSubscription->id)->exists())->toBeFalse()
        ->and($entry->refresh()->status)->toBe('approved');
});

it('ignores selecting a member outside the candidates', function (): void {
    $location = Location::factory()->create();
    $member = liveCheckInMember();
    $stranger = liveCheckInMember(['contact' => '5550000000']);
    $entry = liveCheckInEntry($location, [
        'candidate_member_ids' => [$member->id],
    ]);

    Livewire::actingAs(liveCheckInStaff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->call('selectCheckInMember', $stranger->id)
        ->assertSet('selectedCheckInMemberId', $member->id);
});

it('blocks the approve when the member has an overdue invoice', function (): void {
    $location = Location::factory()->create();
    $plan = liveCheckInPlan();
    $member = liveCheckInMember();
    $subscription = liveCheckInSubscription($member, $plan);

    Invoice::factory()->create([
        'subscription_id' => $subscription->id,
        'status' => Status::Issued,
        'subscription_fee' => 100,
        'discount' => 0,
        'discount_amount' => 0,
        'paid_amount' => 0,
        'due_date' => now()->subDay(),
    ]);

    $entry = liveCheckInEntry($location, ['candidate_member_ids' => [$member->id]]);

    Livewire::actingAs(liveCheckInStaff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->set('checkInServiceId', $plan->service_id)
        ->call('approveCheckIn')
        ->assertDispatched('notify')
        ->assertSet('showCheckInOverlay', true);

    expect($entry->refresh()->status)->toBe('waiting')
        ->and(PlanCheckIn::count())->toBe(0);
});

it('closes the checkin overlay when the entry is resolved elsewhere', function (): void {
    $location = Location::factory()->create();
    $member = liveCheckInMember();
    $entry = liveCheckInEntry($location, ['candidate_member_ids' => [$member->id]]);

    Livewire::actingAs(liveCheckInStaff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->assertSet('showCheckInOverlay', true)
        ->call('onQueueEntryResolved', ['queueEntryId' => $entry->id])
        ->assertDispatched('notify')
        ->assertSet('showCheckInOverlay', false)
        ->assertSet('selectedCheckInEntryId', null);
});

it('closes the checkin overlay without notifying when the entry expires elsewhere', function (): void {
    $location = Location::factory()->create();
    $member = liveCheckInMember();
    $entry = liveCheckInEntry($location, ['candidate_member_ids' => [$member->id]]);

    Livewire::actingAs(liveCheckInStaff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->assertSet('showCheckInOverlay', true)
        ->call('onQueueEntryExpired', ['queueEntryId' => $entry->id])
        ->assertNotDispatched('notify')
        ->assertSet('showCheckInOverlay', false)
        ->assertSet('selectedCheckInEntryId', null);
});

it('resets the checkin overlay state when switching tabs', function (): void {
    $location = Location::factory()->create();
    $member = liveCheckInMember();
    $entry = liveCheckInEntry($location, ['candidate_member_ids' => [$member->id]]);

    Livewire::actingAs(liveCheckInStaff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->assertSet('showCheckInOverlay', true)
        ->call('setActiveTab', 'signup')
        ->assertSet('showCheckInOverlay', false)
        ->assertSet('selectedCheckInEntryId', null)
        ->assertSet('selectedCheckInMemberId', null)
        ->assertSet('checkInServiceId', null)
        ->assertSet('checkInDenyStep', false)
        ->assertSet('checkInPopupQueue', []);
});

it('auto-opens the checkin overlay from the global popup on any admin page', function (): void {
    $location = Location::factory()->create();
    $member = liveCheckInMember();
    $entry = liveCheckInEntry($location, ['candidate_member_ids' => [$member->id]]);

    Livewire::actingAs(liveCheckInStaff())
        ->test(LiveSignupPopup::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->assertSet('showCheckInOverlay', true)
        ->assertSet('selectedCheckInEntryId', $entry->id)
        ->assertSet('selectedCheckInMemberId', $member->id);
});

it('delegates the checkin popup to the reception page and stays silent there', function (): void {
    $location = Location::factory()->create();
    $member = liveCheckInMember();
    $entry = liveCheckInEntry($location, ['candidate_member_ids' => [$member->id]]);

    Livewire::actingAs(liveCheckInStaff())
        ->test(LiveSignupPopup::class)
        ->set('popupEnabled', false)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->assertSet('showCheckInOverlay', false)
        ->assertSet('selectedCheckInEntryId', null);
});

it('queues checkin entries arriving while the global popup overlay is open', function (): void {
    $location = Location::factory()->create();
    $firstMember = liveCheckInMember();
    $secondMember = liveCheckInMember(['contact' => '5551112222']);
    $first = liveCheckInEntry($location, ['candidate_member_ids' => [$firstMember->id]]);
    $second = liveCheckInEntry($location, ['candidate_member_ids' => [$secondMember->id]]);

    Livewire::actingAs(liveCheckInStaff())
        ->test(LiveSignupPopup::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $first->id])
        ->assertSet('showCheckInOverlay', true)
        ->call('onQueueEntryCreated', ['queueEntryId' => $second->id])
        ->assertSet('checkInPopupQueue', [$second->id])
        ->call('closeCheckInOverlay')
        ->assertSet('showCheckInOverlay', true)
        ->assertSet('selectedCheckInEntryId', $second->id)
        ->assertSet('checkInPopupQueue', []);
});

it('approves a check-in from the global popup and records the PlanCheckIn', function (): void {
    $location = Location::factory()->create();
    $plan = liveCheckInPlan();
    $member = liveCheckInMember();
    $subscription = liveCheckInSubscription($member, $plan);
    $entry = liveCheckInEntry($location, ['candidate_member_ids' => [$member->id]]);

    Livewire::actingAs(liveCheckInStaff())
        ->test(LiveSignupPopup::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->set('checkInServiceId', $plan->service_id)
        ->call('approveCheckIn')
        ->assertDispatched('notify')
        ->assertSet('showCheckInOverlay', false);

    expect($entry->refresh()->status)->toBe('approved')
        ->and(PlanCheckIn::where('subscription_id', $subscription->id)->exists())->toBeTrue();
});

it('closes the global checkin overlay when the entry is resolved elsewhere', function (): void {
    $location = Location::factory()->create();
    $member = liveCheckInMember();
    $entry = liveCheckInEntry($location, ['candidate_member_ids' => [$member->id]]);

    Livewire::actingAs(liveCheckInStaff())
        ->test(LiveSignupPopup::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->assertSet('showCheckInOverlay', true)
        ->call('onQueueEntryResolved', ['queueEntryId' => $entry->id])
        ->assertDispatched('notify')
        ->assertSet('showCheckInOverlay', false)
        ->assertSet('selectedCheckInEntryId', null);
});

it('submits a checkin for a single active member with the original payload shape', function (): void {
    $location = Location::factory()->create();
    $token = LocationToken::factory()->checkin()->create([
        'tokenable_type' => Location::class,
        'tokenable_id' => $location->id,
    ]);
    $plan = liveCheckInPlan();
    $member = liveCheckInMember(['contact' => '5551234567']);
    liveCheckInSubscription($member, $plan);

    $this->postJson(route('checkin.submit'), [
        'kind' => 'checkin',
        'identifier_type' => 'contact',
        'value' => '5551234567',
        'token' => $token->token,
    ])
        ->assertOk()
        ->assertJson(['match' => true])
        ->assertJsonPath('member.id', $member->id)
        ->assertJsonPath('queue_entry_uuid', fn ($uuid) => QueueEntry::where('uuid', $uuid)->exists());

    $entry = QueueEntry::where('kind', 'checkin')->first();
    expect($entry->payload['member_id'])->toBe($member->id)
        ->and($entry->payload['subscription_id'])->toBe($plan->subscriptions()->first()->id);
});

it('returns every active candidate when an identifier matches multiple members', function (): void {
    $location = Location::factory()->create();
    $token = LocationToken::factory()->checkin()->create([
        'tokenable_type' => Location::class,
        'tokenable_id' => $location->id,
    ]);
    $plan = liveCheckInPlan();
    $first = liveCheckInMember(['contact' => '5559876543']);
    $second = liveCheckInMember(['contact' => '5559876543']);
    liveCheckInSubscription($first, $plan);
    liveCheckInSubscription($second, $plan);

    $this->postJson(route('checkin.submit'), [
        'kind' => 'checkin',
        'identifier_type' => 'contact',
        'value' => '5559876543',
        'token' => $token->token,
    ])
        ->assertOk()
        ->assertJson(['match' => true])
        ->assertJsonPath('members.0.id', $first->id)
        ->assertJsonPath('members.1.id', $second->id)
        ->assertJsonPath('queue_entry_uuid', fn ($uuid) => QueueEntry::where('uuid', $uuid)->exists());

    $entry = QueueEntry::where('kind', 'checkin')->first();
    expect($entry->payload['candidate_member_ids'])->toBe([$first->id, $second->id])
        ->and($entry->payload['member_id'] ?? null)->toBeNull();
});

it('returns match false with an inactive message when all matches are inactive', function (): void {
    $location = Location::factory()->create();
    $token = LocationToken::factory()->checkin()->create([
        'tokenable_type' => Location::class,
        'tokenable_id' => $location->id,
    ]);
    liveCheckInMember(['contact' => '5559876543', 'status' => Status::Inactive]);
    liveCheckInMember(['contact' => '5559876543', 'status' => Status::Inactive]);

    $this->postJson(route('checkin.submit'), [
        'kind' => 'checkin',
        'identifier_type' => 'contact',
        'value' => '5559876543',
        'token' => $token->token,
    ])
        ->assertOk()
        ->assertJson(['match' => false])
        ->assertJsonPath('message', __('app.reception.check_in_member_inactive'));

    expect(QueueEntry::count())->toBe(0);
});

it('returns match false when the identifier matches nothing', function (): void {
    $location = Location::factory()->create();
    $token = LocationToken::factory()->checkin()->create([
        'tokenable_type' => Location::class,
        'tokenable_id' => $location->id,
    ]);

    $this->postJson(route('checkin.submit'), [
        'kind' => 'checkin',
        'identifier_type' => 'contact',
        'value' => '9999999999',
        'token' => $token->token,
    ])
        ->assertOk()
        ->assertJson(['match' => false]);
});

it('lists every location service in the overlay and accepts picking an access service', function (): void {
    $location = Location::factory()->create();
    $plan = liveCheckInPlan();
    $member = liveCheckInMember();
    liveCheckInSubscription($member, $plan);
    $entry = liveCheckInEntry($location, ['candidate_member_ids' => [$member->id]]);

    Livewire::actingAs(liveCheckInStaff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->call('selectCheckInService', $plan->service_id)
        ->assertSet('checkInServiceId', $plan->service_id);
});

it('approves against the eligible subscription with the latest end date for the picked service', function (): void {
    $location = Location::factory()->create();
    $plan = liveCheckInPlan();
    $member = liveCheckInMember();
    $soon = liveCheckInSubscription($member, $plan, ['end_date' => now()->addDays(10)->toDateString()]);
    $later = liveCheckInSubscription($member, $plan, ['end_date' => now()->addDays(30)->toDateString()]);
    $entry = liveCheckInEntry($location, ['candidate_member_ids' => [$member->id]]);

    Livewire::actingAs(liveCheckInStaff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->set('checkInServiceId', $plan->service_id)
        ->call('approveCheckIn')
        ->assertDispatched('notify')
        ->assertSet('showCheckInOverlay', false);

    expect(PlanCheckIn::where('subscription_id', $later->id)->exists())->toBeTrue()
        ->and(PlanCheckIn::where('subscription_id', $soon->id)->exists())->toBeFalse()
        ->and($entry->refresh()->status)->toBe('approved');
});

it('blocks the approve for an unpaid invoice and routes staff to the payment / due-date modals', function (): void {
    Feature::activate('checkin.override');

    $location = Location::factory()->create();
    $plan = liveCheckInPlan();
    $member = liveCheckInMember();
    $subscription = liveCheckInSubscription($member, $plan);

    Invoice::factory()->create([
        'subscription_id' => $subscription->id,
        'status' => Status::Issued,
        'subscription_fee' => 100,
        'discount' => 0,
        'discount_amount' => 0,
        'paid_amount' => 0,
        'due_date' => now()->addDays(5),
    ]);

    $entry = liveCheckInEntry($location, ['candidate_member_ids' => [$member->id]]);

    Livewire::actingAs(liveCheckInStaff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->set('checkInServiceId', $plan->service_id)
        ->call('approveCheckIn')
        ->assertDispatched('notify')
        ->assertSet('showCheckInOverlay', true)
        // The generic override is gone for past-due states (O5): the footer
        // offers the payment / due-date modals and the action refuses.
        ->call('openCheckInOverrideFor', $plan->service_id)
        ->assertSet('checkInOverrideStep', false)
        ->call('openAddPaymentModal', $plan->service_id)
        ->assertDispatched('open-add-payment-modal')
        ->call('openChangeDueDateModal', $plan->service_id)
        ->assertDispatched('open-change-due-date-modal');

    expect(PlanCheckIn::count())->toBe(0);
});

it('keeps the strict gate for an overdue member: approve and crafted overrides both blocked', function (): void {
    Feature::activate('checkin.override');

    $location = Location::factory()->create();
    $plan = liveCheckInPlan();
    $member = liveCheckInMember();
    $subscription = liveCheckInSubscription($member, $plan);

    Invoice::factory()->create([
        'subscription_id' => $subscription->id,
        'status' => Status::Issued,
        'subscription_fee' => 100,
        'discount' => 0,
        'discount_amount' => 0,
        'paid_amount' => 0,
        'due_amount' => 100,
        'due_date' => now()->subDay(),
    ]);

    $entry = liveCheckInEntry($location, ['candidate_member_ids' => [$member->id]]);

    Livewire::actingAs(liveCheckInStaff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->set('checkInServiceId', $plan->service_id)
        ->call('approveCheckIn')
        ->assertDispatched('notify')
        ->assertSet('showCheckInOverlay', true)
        ->call('openCheckInOverrideFor', $plan->service_id)
        ->assertSet('checkInOverrideStep', false)
        ->set('checkInServiceId', $plan->service_id)
        ->call('confirmCheckInOverride')
        ->assertDispatched('notify')
        ->assertSet('showCheckInOverlay', true);

    expect(PlanCheckIn::count())->toBe(0)
        ->and($entry->refresh()->status)->toBe('waiting');
});

it('allows overriding a service the member has no subscription for and records the reason', function (): void {
    Feature::activate('checkin.override');

    $location = Location::factory()->create();
    $plan = liveCheckInPlan();
    $member = liveCheckInMember();
    $entry = liveCheckInEntry($location, ['candidate_member_ids' => [$member->id]]);

    Livewire::actingAs(liveCheckInStaff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->set('checkInServiceId', $plan->service_id)
        ->call('openCheckInOverrideFor', $plan->service_id)
        ->assertSet('checkInOverrideStep', true)
        ->call('confirmCheckInOverride')
        ->assertDispatched('notify')
        ->assertSet('showCheckInOverlay', false);

    $checkIn = PlanCheckIn::first();
    expect($checkIn)->not->toBeNull()
        ->and($checkIn->member_id)->toBe($member->id)
        ->and($checkIn->subscription_id)->toBeNull()
        ->and($checkIn->plan_id)->toBeNull()
        ->and($checkIn->service_id)->toBe($plan->service_id)
        ->and($checkIn->override)->toBeTrue()
        ->and($checkIn->override_reason)->toBe('no_subscription')
        ->and($entry->refresh()->status)->toBe('approved');
});

it('adds check-in entries to the combined waiting line when the popup is delegated', function (): void {
    $location = Location::factory()->create();
    $member = liveCheckInMember();
    $entry = liveCheckInEntry($location, ['candidate_member_ids' => [$member->id]]);

    Livewire::actingAs(liveCheckInStaff())
        ->test(LiveSignupPopup::class)
        ->set('popupEnabled', false)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->assertSet('showCheckInOverlay', false)
        ->assertSet('pendingQueue', fn (array $queue): bool => collect($queue)->contains(
            fn (array $item): bool => (int) $item['id'] === (int) $entry->id && $item['kind'] === 'checkin' && $item['name'] === $member->name
        ));
});

it('opens the check-in overlay from the waiting line and removes the entry', function (): void {
    $location = Location::factory()->create();
    $member = liveCheckInMember();
    $entry = liveCheckInEntry($location, ['candidate_member_ids' => [$member->id]]);

    Livewire::actingAs(liveCheckInStaff())
        ->test(LiveSignupPopup::class)
        ->set('popupEnabled', false)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->call('checkInFromPending', $entry->id)
        ->assertSet('showCheckInOverlay', true)
        ->assertSet('selectedCheckInEntryId', $entry->id)
        ->assertSet('pendingQueue', []);
});

it('re-adds a check-in closed without action to the waiting line', function (): void {
    $location = Location::factory()->create();
    $member = liveCheckInMember();
    $entry = liveCheckInEntry($location, ['candidate_member_ids' => [$member->id]]);

    Livewire::actingAs(liveCheckInStaff())
        ->test(LiveSignupPopup::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->assertSet('showCheckInOverlay', true)
        ->call('closeCheckInOverlay')
        ->assertSet('pendingQueue', fn (array $queue): bool => collect($queue)->pluck('id')->contains($entry->id));
});

it('seeds the waiting line with check-in entries on mount', function (): void {
    $location = Location::factory()->create();
    $member = liveCheckInMember();
    liveCheckInEntry($location, ['candidate_member_ids' => [$member->id]]);

    Livewire::actingAs(liveCheckInStaff())
        ->test(LiveSignupPopup::class)
        ->assertSet('pendingQueue', fn (array $queue): bool => collect($queue)->pluck('kind')->contains('checkin'));
});

it('creates a PlanCheckIn when the check-in toggle is opted in during signup', function (): void {
    $location = Location::factory()->create();
    $plan = liveCheckInPlan();
    $entry = liveCheckInSignupEntry($location);
    $photo = liveCheckInPhoto();

    Livewire::actingAs(liveCheckInStaff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->assertSet('showVerifyOverlay', true)
        ->set('verifyPhoto', $photo)
        ->call('verifyContinue')
        ->assertSet('verifyStep', 2)
        ->call('verifyContinue')
        ->assertSet('verifyStep', 3)
        ->set('verifyForm.sale', liveCheckInSale($plan))
        ->set('verifyCheckIn', true)
        ->call('confirmSignup')
        ->assertDispatched('notify')
        ->assertSet('verifyStep', 4)
        ->assertSet('showVerifyOverlay', true)
        ->call('confirmVerifyCheckIn')
        ->assertDispatched('notify')
        ->assertSet('showVerifyOverlay', false);

    $member = Member::where('contact', '5559876543')->first();
    expect($member)->not->toBeNull();

    $subscription = Subscription::where('member_id', $member->id)->first();
    expect($subscription)->not->toBeNull();

    expect(PlanCheckIn::count())->toBe(1);

    $checkIn = PlanCheckIn::first();
    expect($checkIn->member_id)->toBe($member->id)
        ->and($checkIn->subscription_id)->toBe($subscription->id)
        ->and($checkIn->plan_id)->toBe($plan->id)
        ->and($checkIn->service_id)->toBe($plan->service_id);
});

it('skips the check-in and closes the overlay when the toggle is off', function (): void {
    $location = Location::factory()->create();
    $plan = liveCheckInPlan();
    $entry = liveCheckInSignupEntry($location);
    $photo = liveCheckInPhoto();

    Livewire::actingAs(liveCheckInStaff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->set('verifyPhoto', $photo)
        ->call('verifyContinue')
        ->call('verifyContinue')
        ->set('verifyForm.sale', liveCheckInSale($plan))
        ->set('verifyCheckIn', false)
        ->call('confirmSignup')
        ->assertDispatched('notify')
        ->assertSet('showVerifyOverlay', false);

    expect(PlanCheckIn::count())->toBe(0);
    $member = Member::where('contact', '5559876543')->first();
    expect($member)->not->toBeNull();
});

it('shows a warning toast when the post-signup check-in fails and still closes the overlay', function (): void {
    $location = Location::factory()->create();
    $plan = liveCheckInPlan();
    $entry = liveCheckInSignupEntry($location);
    $photo = liveCheckInPhoto();

    Livewire::actingAs(liveCheckInStaff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->set('verifyPhoto', $photo)
        ->call('verifyContinue')
        ->call('verifyContinue')
        ->set('verifyForm.sale', liveCheckInSale($plan))
        ->set('verifyCheckIn', true)
        ->call('confirmSignup')
        ->assertSet('verifyStep', 4);

    // Simulate failure: delete the subscription so the check-in can't find it.
    $member = Member::where('contact', '5559876543')->first();
    expect($member)->not->toBeNull();
    Subscription::where('member_id', $member->id)->delete();

    Livewire::actingAs(liveCheckInStaff())
        ->test(Reception::class)
        ->set('verifyCreatedMember', $member)
        ->set('verifyCheckInServiceId', $plan->service_id)
        ->set('selectedQueueEntryId', $entry->id)
        ->call('confirmVerifyCheckIn')
        ->assertDispatched('notify')
        ->assertSet('showVerifyOverlay', false);

    expect(PlanCheckIn::count())->toBe(0)
        ->and(Member::where('contact', '5559876543')->exists())->toBeTrue();
});

it('resets the verifyCheckIn toggle when the overlay is closed', function (): void {
    $location = Location::factory()->create();

    Livewire::actingAs(liveCheckInStaff())
        ->test(Reception::class)
        ->set('verifyCheckIn', true)
        ->call('closeVerifyOverlay')
        ->assertSet('verifyCheckIn', false)
        ->assertSet('verifyCheckInServiceId', null)
        ->assertSet('verifyCreatedMember', null);
});

it('closes the overlay from step 4 without check-in and keeps the created member', function (): void {
    $location = Location::factory()->create();
    $plan = liveCheckInPlan();
    $entry = liveCheckInSignupEntry($location);
    $photo = liveCheckInPhoto();

    Livewire::actingAs(liveCheckInStaff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->set('verifyPhoto', $photo)
        ->call('verifyContinue')
        ->call('verifyContinue')
        ->set('verifyForm.sale', liveCheckInSale($plan))
        ->set('verifyCheckIn', true)
        ->call('confirmSignup')
        ->assertSet('verifyStep', 4)
        ->call('closeVerifyOverlay')
        ->assertSet('showVerifyOverlay', false);

    expect(PlanCheckIn::count())->toBe(0)
        ->and(Member::where('contact', '5559876543')->exists())->toBeTrue();
});

it('computes verifyCheckInServices from the selected plan after signup is saved', function (): void {
    $location = Location::factory()->create();
    $plan = liveCheckInPlan();
    $entry = liveCheckInSignupEntry($location);
    $photo = liveCheckInPhoto();

    Livewire::actingAs(liveCheckInStaff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->set('verifyPhoto', $photo)
        ->call('verifyContinue')
        ->call('verifyContinue')
        ->set('verifyForm.sale', liveCheckInSale($plan))
        ->set('verifyCheckIn', true)
        ->call('confirmSignup')
        ->assertSet('verifyStep', 4)
        ->assertSet('verifyCheckInServices', function ($services) use ($plan) {
            return count($services) === 1
                && (int) $services[0]['id'] === (int) $plan->service_id
                && $services[0]['state'] === 'access'
                && $services[0]['name'] === $plan->service->name;
        });
});

it('paints the photo border from applicable statuses only, not every picker row', function (): void {
    $location = Location::factory()->create();
    $entitled = liveCheckInPlan();
    $unrelated = liveCheckInPlan();
    $member = liveCheckInMember();
    liveCheckInSubscription($member, $entitled, ['end_date' => now()->addDays(60)->toDateString()]);
    $entry = liveCheckInEntry($location, ['candidate_member_ids' => [$member->id]]);

    // Pin the expiring window so the far-out end date reads as valid green.
    \App\Helpers\Helpers::setTestSettingsOverride([
        'subscriptions' => ['expiring_days' => 7],
    ]);

    $component = Livewire::actingAs(liveCheckInStaff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->set('checkInServiceId', $entitled->service_id);

    // Valid member checking into their access row: the unrelated service
    // they are NOT entitled to must not paint the whole card red.
    expect($component->html())->toContain('var(--success-500)')
        ->not->toContain('var(--danger-500)');

    // Deliberately picking the non-access row IS decision-relevant: the
    // border flips to danger and matches that row's badge.
    $component->set('checkInServiceId', $unrelated->service_id);

    expect($component->html())->toContain('var(--danger-500)');

    \App\Helpers\Helpers::setTestSettingsOverride(null);
});
