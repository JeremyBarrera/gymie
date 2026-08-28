<?php

use App\Enums\Status;
use App\Exceptions\PlanCheckIn\MemberInactiveException;
use App\Filament\Pages\Reception;
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
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'owner']);
});

function g2Plan(): Plan
{
    $service = Service::factory()->create();
    $plan = Plan::factory()->create([
        'amount' => 100,
        'limit_uses' => false,
        'status' => Status::Active,
    ]);
    $plan->services()->attach($service->id);

    return $plan;
}

function g2Member(array $overrides = []): Member
{
    return Member::factory()->create(array_merge([
        'status' => Status::Active,
        'contact' => '5559876543',
        'government_id' => 'GOV123456',
    ], $overrides));
}

function g2Subscription(Member $member, Plan $plan): Subscription
{
    return Subscription::factory()->create([
        'member_id' => $member->id,
        'plan_id' => $plan->id,
        'status' => Status::Ongoing,
        'start_date' => now()->subDays(5)->toDateString(),
        'end_date' => now()->addDays(25)->toDateString(),
    ]);
}

function g2Staff(): User
{
    $staff = User::factory()->create();
    $staff->assignRole('owner');

    return $staff;
}

it('keeps an inactive member with an ongoing subscription eligible', function (): void {
    $plan = g2Plan();
    $member = g2Member(['status' => Status::Inactive]);
    $subscription = g2Subscription($member, $plan);

    $eligible = app(PlanCheckInService::class)->eligibleSubscriptions($member);

    expect($eligible->pluck('id'))->toContain((int) $subscription->id)
        ->and($member->checkInBlocker())->toBeNull();
});

it('empties eligibility for banned members regardless of subscriptions', function (): void {
    $plan = g2Plan();
    $member = g2Member(['status' => Status::Banned]);
    g2Subscription($member, $plan);

    expect(app(PlanCheckInService::class)->eligibleSubscriptions($member))->toBeEmpty()
        ->and($member->checkInBlocker())->toBe('banned');
});

it('records a normal check-in for an inactive member and refuses a banned one', function (): void {
    $plan = g2Plan();
    $staff = User::factory()->create();
    $staff->assignRole('owner');

    $inactive = g2Member(['status' => Status::Inactive]);
    $subscription = g2Subscription($inactive, $plan);

    $checkIn = app(PlanCheckInService::class)->checkIn($inactive, $subscription, $staff);

    expect((bool) $checkIn->override)->toBeFalse();

    $banned = g2Member(['status' => Status::Banned]);
    $bannedSubscription = g2Subscription($banned, $plan);

    try {
        app(PlanCheckInService::class)->checkIn($banned, $bannedSubscription, $staff);
        $this->fail('Expected the banned member to be refused.');
    } catch (MemberInactiveException $exception) {
        expect($exception->getMessage())->toBe(__('app.reception.check_in_member_banned'));
    }
});

it('allows an inactive member through the override path and refuses a banned one', function (): void {
    $plan = g2Plan();
    $staff = User::factory()->create();
    $staff->assignRole('owner');

    $inactive = g2Member(['status' => Status::Inactive]);
    $subscription = g2Subscription($inactive, $plan);

    app(PlanCheckInService::class)->checkInOverride($inactive, $subscription, $staff, 'gate busy');

    expect(PlanCheckIn::query()->where('override', true)->count())->toBe(1);

    $banned = g2Member(['status' => Status::Banned]);
    $bannedSubscription = g2Subscription($banned, $plan);

    try {
        app(PlanCheckInService::class)->checkInOverride($banned, $bannedSubscription, $staff, 'gate busy');
        $this->fail('Expected the banned member to be refused.');
    } catch (MemberInactiveException $exception) {
        expect($exception->getMessage())->toBe(__('app.reception.check_in_member_banned'));
    }
});

it('answers a banned member with the identical neutral public refusal', function (): void {
    $location = Location::factory()->create();
    LocationToken::factory()->create([
        'tokenable_id' => $location->id,
        'tokenable_type' => Location::class,
        'kind' => 'checkin',
    ]);
    $token = $location->tokens()->where('kind', 'checkin')->value('token');

    g2Member(['status' => Status::Banned, 'contact' => '5551112222']);

    $unknown = $this->postJson(route('checkin.submit'), [
        'kind' => 'checkin', 'token' => $token, 'identifier_type' => 'contact', 'value' => '5550000000',
    ]);
    $banned = $this->postJson(route('checkin.submit'), [
        'kind' => 'checkin', 'token' => $token, 'identifier_type' => 'contact', 'value' => '5551112222',
    ]);

    expect($banned->json())->toBe($unknown->json())
        ->and($banned->json('match'))->toBeFalse();
});

it('routes an inactive member with a subscription into the staff queue', function (): void {
    $location = Location::factory()->create();
    LocationToken::factory()->create([
        'tokenable_id' => $location->id,
        'tokenable_type' => Location::class,
        'kind' => 'checkin',
    ]);
    $token = $location->tokens()->where('kind', 'checkin')->value('token');

    $plan = g2Plan();
    $member = g2Member(['status' => Status::Inactive, 'contact' => '5552223333']);
    g2Subscription($member, $plan);

    $response = $this->postJson(route('checkin.submit'), [
        'kind' => 'checkin',
        'token' => $token,
        'identifier_type' => 'contact',
        'value' => '5552223333',
    ]);

    $entry = QueueEntry::query()->where('kind', 'checkin')->first();

    expect($response->json('match'))->toBeTrue()
        ->and($entry)->not->toBeNull()
        ->and($entry->payload['member_id'])->toBe((int) $member->id);
});

it('matches an inactive member with a subscription on the api and refuses a banned one', function (): void {
    $plan = g2Plan();
    $inactive = g2Member(['status' => Status::Inactive, 'contact' => '5552223333']);
    g2Subscription($inactive, $plan);

    $matched = $this->postJson('/api/v1/checkin/lookup', [
        'identifier_type' => 'contact',
        'value' => '5552223333',
    ]);

    expect($matched->json('match'))->toBeTrue()
        ->and($matched->json('member.id'))->toBe((int) $inactive->id);

    $banned = g2Member(['status' => Status::Banned, 'contact' => '5551112222']);

    $refused = $this->postJson('/api/v1/checkin/lookup', [
        'identifier_type' => 'contact',
        'value' => '5551112222',
    ]);

    $unknown = $this->postJson('/api/v1/checkin/lookup', [
        'identifier_type' => 'contact',
        'value' => '5550000000',
    ]);

    expect($refused->json())->toBe($unknown->json());
});

it('opens the walk-up overlay for an inactive member and for a banned one with a banned warning', function (): void {
    $plan = g2Plan();
    g2Subscription(g2Member(['name' => 'Inactive Ivy', 'status' => Status::Inactive]), $plan);

    Livewire::actingAs(g2Staff())
        ->test(Reception::class)
        ->set('manualCheckInSearch', 'Ivy')
        ->call('openManualCheckInOverlay')
        ->assertSet('showCheckInOverlay', true);

    $banned = g2Member(['name' => 'Banned Bob', 'status' => Status::Banned, 'ban_reason' => 'Test ban']);

    Livewire::actingAs(g2Staff())
        ->test(Reception::class)
        ->set('manualCheckInSearch', 'Bob')
        ->call('openManualCheckInOverlay')
        ->assertSet('showCheckInOverlay', true)
        ->assertSet('selectedCheckInMemberId', $banned->id)
        ->assertSee(__('app.members.banned'));
});
