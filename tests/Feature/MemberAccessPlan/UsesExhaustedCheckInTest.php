<?php

use App\Enums\Status;
use App\Filament\Pages\Reception;
use App\Helpers\Helpers;
use App\Models\Location;
use App\Models\LocationToken;
use App\Models\Member;
use App\Models\Plan;
use App\Models\PlanCheckIn;
use App\Models\QueueEntry;
use App\Models\Service;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Membership\PlanCheckInService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'owner']);
});

afterEach(function (): void {
    Helpers::setTestSettingsOverride(null);
});

function m5Staff(): User
{
    $staff = User::factory()->create();
    $staff->assignRole('owner');

    return $staff;
}

function m5LimitedPlan(int $limit): Plan
{
    $service = Service::factory()->create();
    $plan = Plan::factory()->create([
        'amount' => 100,
        'limit_uses' => true,
        'uses_limit' => $limit,
        'status' => Status::Active,
    ]);
    $plan->services()->attach($service->id);

    return $plan;
}

function m5Member(array $overrides = []): Member
{
    return Member::factory()->create(array_merge([
        'status' => Status::Active,
        'contact' => '5559876543',
    ], $overrides));
}

function m5OngoingSubscription(Member $member, Plan $plan): Subscription
{
    return Subscription::factory()->create([
        'member_id' => $member->id,
        'plan_id' => $plan->id,
        'status' => Status::Ongoing,
        'start_date' => now()->subDays(5)->toDateString(),
        'end_date' => now()->addDays(25)->toDateString(),
    ]);
}

function m5ExhaustUses(Subscription $subscription, int $count): void
{
    $member = $subscription->member;

    PlanCheckIn::factory()->count($count)->create([
        'member_id' => $member->id,
        'subscription_id' => $subscription->id,
        'plan_id' => $subscription->plan_id,
        'override' => false,
        'checked_in_at' => now()->subDay(),
    ]);
}

function m5Entry(Location $location, Member $member): QueueEntry
{
    return QueueEntry::create([
        'uuid' => (string) Str::uuid(),
        'location_id' => $location->id,
        'kind' => 'checkin',
        'payload' => ['candidate_member_ids' => [$member->id]],
        'identifier_type' => 'contact',
        'status' => 'waiting',
        'expires_at' => now()->addMinutes(10),
    ]);
}

function m5ConfigureRecipients(User $pinned): void
{
    Role::firstOrCreate(['name' => 'manager']);

    Helpers::setTestSettingsOverride([
        'notifications' => [
            'follow_up' => [
                'roles' => ['manager'],
                'users' => [$pinned->id],
            ],
        ],
    ]);
}

it('reports uses_exhausted instead of access when the plan quota is spent', function (): void {
    $plan = m5LimitedPlan(2);
    $member = m5Member();
    $subscription = m5OngoingSubscription($member, $plan);
    m5ExhaustUses($subscription, 2);

    $rows = app(PlanCheckInService::class)->serviceStatesForMember($member);

    $row = collect($rows)->firstWhere('id', (int) $plan->primaryService()->id);

    expect($row['state'])->toBe('uses_exhausted')
        ->and($row['subscription_id'])->toBe((int) $subscription->id);
});

it('keeps unlimited plans and subscriptions with uses left on access', function (): void {
    $limited = m5LimitedPlan(3);
    $member = m5Member();
    $subscription = m5OngoingSubscription($member, $limited);
    m5ExhaustUses($subscription, 1);

    $service = Service::factory()->create();
    $unlimitedPlan = Plan::factory()->create([
        'amount' => 50,
        'limit_uses' => false,
        'status' => Status::Active,
    ]);
    $unlimitedPlan->services()->attach($service->id);
    m5OngoingSubscription($member, $unlimitedPlan);

    $states = app(PlanCheckInService::class)->serviceStatesForMember($member);

    expect(collect($states)->firstWhere('id', (int) $limited->primaryService()->id)['state'])->toBe('access')
        ->and(collect($states)->firstWhere('id', (int) $service->id)['state'])->toBe('access');
});

it('resolves expired before uses exhausted when both apply', function (): void {
    $plan = m5LimitedPlan(1);
    $member = m5Member();
    $expired = Subscription::factory()->create([
        'member_id' => $member->id,
        'plan_id' => $plan->id,
        'status' => Status::Expired,
        'start_date' => now()->subDays(35)->toDateString(),
        'end_date' => now()->subDays(5)->toDateString(),
    ]);
    m5ExhaustUses($expired, 1);

    $states = app(PlanCheckInService::class)->serviceStatesForMember($member);

    expect(collect($states)->firstWhere('id', (int) $plan->primaryService()->id)['state'])->toBe('expired');
});

it('opens the renewal popup for a uses-exhausted service', function (): void {
    $location = Location::factory()->create();
    $plan = m5LimitedPlan(1);
    $member = m5Member();
    $subscription = m5OngoingSubscription($member, $plan);
    m5ExhaustUses($subscription, 1);
    $entry = m5Entry($location, $member);

    Livewire::actingAs(m5Staff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->call('openExpiredSubscriptionModal', (int) $plan->primaryService()->id)
        ->assertDispatched('open-expired-subscription-modal',
            memberId: (int) $member->id,
            serviceId: (int) $plan->primaryService()->id,
            previousSubscriptionId: (int) $subscription->id,
        );
});

it('refuses the normal approve on a uses-exhausted service', function (): void {
    $location = Location::factory()->create();
    $plan = m5LimitedPlan(1);
    $member = m5Member();
    $subscription = m5OngoingSubscription($member, $plan);
    m5ExhaustUses($subscription, 1);
    $entry = m5Entry($location, $member);

    Livewire::actingAs(m5Staff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->call('approveCheckIn');

    expect(PlanCheckIn::count())->toBe(1)
        ->and(PlanCheckIn::query()->where('override', true)->count())->toBe(0)
        ->and($entry->refresh()->status)->toBe('waiting');
});

it('overrides a uses-exhausted service with a system reason and fires the dedicated alert', function (): void {
    Feature::activate('checkin.override');

    $staff = m5Staff();
    $pinned = User::factory()->create();
    m5ConfigureRecipients($pinned);

    $location = Location::factory()->create();
    $plan = m5LimitedPlan(1);
    $member = m5Member();
    $subscription = m5OngoingSubscription($member, $plan);
    m5ExhaustUses($subscription, 1);
    $entry = m5Entry($location, $member);

    Livewire::actingAs($staff)
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->set('checkInServiceId', (int) $plan->primaryService()->id)
        ->call('openCheckInOverrideFor', (int) $plan->primaryService()->id)
        ->assertSet('checkInOverrideStep', true)
        ->call('confirmCheckInOverride')
        ->assertSet('showCheckInOverlay', false);

    $checkIn = PlanCheckIn::query()->where('override', true)->first();

    
    $payload = $pinned->unreadNotifications()->first()->data;

    expect($checkIn)->not->toBeNull()
        ->and($checkIn->override_reason)->toBe(__('app.reception.service_uses_exhausted', ['plan' => $plan->name]))
        ->and($payload['action'])->toBe('uses_exhausted_override')
        ->and($payload['reason'])->toBe(__('app.reception.service_uses_exhausted', ['plan' => $plan->name]))
        ->and($payload['actor']['id'])->toBe((int) $staff->id)
        ->and($entry->refresh()->status)->toBe('approved');
});

it('keeps the override step exclusive to no-access and uses-exhausted rows', function (): void {
    Feature::activate('checkin.override');

    $location = Location::factory()->create();
    $unlimitedPlan = Plan::factory()->create([
        'amount' => 50,
        'limit_uses' => false,
        'status' => Status::Active,
    ]);
    $unlimitedPlan->services()->attach(Service::factory()->create(['name' => 'Crossfit'])->id);
    $member = m5Member();
    $subscription = m5OngoingSubscription($member, $unlimitedPlan);
    $entry = m5Entry($location, $member);

    Livewire::actingAs(m5Staff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->set('checkInServiceId', (int) $unlimitedPlan->primaryService()->id)
        ->call('openCheckInOverrideFor', (int) $unlimitedPlan->primaryService()->id)
        ->assertSet('checkInOverrideStep', false);

    expect(PlanCheckIn::count())->toBe(0);
});

it('answers every public check-in refusal with the identical neutral body', function (): void {
    $location = Location::factory()->create();
    LocationToken::factory()->create([
        'tokenable_id' => $location->id,
        'tokenable_type' => Location::class,
        'kind' => 'checkin',
    ]);

    $token = $location->tokens()->where('kind', 'checkin')->value('token');

    m5Member(['status' => Status::Inactive, 'contact' => '5551112222']);
    
    m5Member(['contact' => '5554445555']);

    $responses = collect([
        'unknown' => '5550000000',
        'inactive' => '5551112222',
        'no_eligible' => '5554445555',
    ])->mapWithKeys(fn (string $value, string $case) => [
        $case => $this->postJson(route('checkin.submit'), [
            'kind' => 'checkin',
            'token' => $token,
            'identifier_type' => 'contact',
            'value' => $value,
        ]),
    ]);

    $bodies = $responses->map(fn ($r) => $r->json());

    expect($responses['unknown']->status())->toBe(200)
        ->and($bodies['unknown'])->toBe($bodies['inactive'])
        ->and($bodies['inactive'])->toBe($bodies['no_eligible'])
        ->and($bodies['unknown']['match'])->toBeFalse()
        ->and($bodies['unknown']['message'])->toContain('front desk');
});

it('routes a uses-exhausted member into the staff queue like any other match', function (): void {
    $location = Location::factory()->create();
    LocationToken::factory()->create([
        'tokenable_id' => $location->id,
        'tokenable_type' => Location::class,
        'kind' => 'checkin',
    ]);

    $plan = m5LimitedPlan(1);
    $member = m5Member(['contact' => '5553334444']);
    $subscription = m5OngoingSubscription($member, $plan);
    m5ExhaustUses($subscription, 1);

    $response = $this->postJson(route('checkin.submit'), [
        'kind' => 'checkin',
        'token' => $location->tokens()->where('kind', 'checkin')->value('token'),
        'identifier_type' => 'contact',
        'value' => '5553334444',
    ]);

    $entry = QueueEntry::query()->where('kind', 'checkin')->first();

    expect($response->json('match'))->toBeTrue()
        ->and($response->json('queue_entry_uuid'))->not->toBeNull()
        ->and($entry)->not->toBeNull()
        ->and($entry->payload['member_id'])->toBe((int) $member->id);
});

it('answers the api lookup for pending, inactive and banned members per the blocker rule', function (): void {
    $pending = m5Member(['status' => Status::Pending, 'contact' => '5554446666']);
    $banned = m5Member(['status' => Status::Banned, 'contact' => '5551112222']);

    $pendingLookup = $this->postJson('/api/v1/checkin/lookup', [
        'identifier_type' => 'contact',
        'value' => '5554446666',
    ]);

    expect($pendingLookup->json('match'))->toBeTrue()
        ->and($pendingLookup->json('member.id'))->toBe((int) $pending->id)
        ->and($pendingLookup->json('member.eligible'))->toBe([]);

    $refused = $this->postJson('/api/v1/checkin/lookup', [
        'identifier_type' => 'contact',
        'value' => '5551112222',
    ]);

    $unknown = $this->postJson('/api/v1/checkin/lookup', [
        'identifier_type' => 'contact',
        'value' => '5550000000',
    ]);

    expect($refused->json())->toBe($unknown->json())
        ->and($refused->json('match'))->toBeFalse()
        ->and($banned->refresh()->status)->toBe(Status::Banned);
});
