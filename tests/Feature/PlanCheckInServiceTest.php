<?php

use App\Enums\Status;
use App\Exceptions\PlanCheckIn\DuplicateCheckInRequiresConfirmationException;
use App\Exceptions\PlanCheckIn\MemberInactiveException;
use App\Exceptions\PlanCheckIn\SubscriptionNotEligibleException;
use App\Exceptions\PlanCheckIn\UsesExceededException;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Membership\PlanCheckInService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createEligibleSubscription(Member $member, Plan $plan, array $overrides = []): Subscription
{
    $today = Carbon::today(config('app.timezone'));

    return Subscription::factory()->create(array_merge([
        'member_id' => $member->id,
        'plan_id' => $plan->id,
        'start_date' => $today->copy()->subDays(10)->toDateString(),
        'end_date' => $today->copy()->addDays(20)->toDateString(),
        'status' => Status::Ongoing->value,
    ], $overrides));
}

it('returns eligible subscriptions for an active member', function (): void {
    $service = app(PlanCheckInService::class);
    $member = Member::factory()->create(['status' => Status::Active->value]);
    $plan = Plan::factory()->create(['status' => Status::Active->value]);
    $subscription = createEligibleSubscription($member, $plan);

    $eligible = $service->eligibleSubscriptions($member);

    expect($eligible)->toHaveCount(1)
        ->and($eligible->first()?->id)->toBe($subscription->id);
});

it('blocks check-in when plan use limit is exceeded', function (): void {
    $service = app(PlanCheckInService::class);
    $member = Member::factory()->create(['status' => Status::Active->value]);
    $plan = Plan::factory()->withUseLimit(1)->create(['status' => Status::Active->value]);
    $subscription = createEligibleSubscription($member, $plan);

    $service->checkIn($member, $subscription);

    expect(fn () => $service->checkIn($member, $subscription, null, true))
        ->toThrow(UsesExceededException::class);
});

it('allows unlimited check-ins when plan does not track uses', function (): void {
    $service = app(PlanCheckInService::class);
    $member = Member::factory()->create(['status' => Status::Active->value]);
    $plan = Plan::factory()->create([
        'status' => Status::Active->value,
        'track_uses' => false,
        'uses_limit' => null,
    ]);
    $subscription = createEligibleSubscription($member, $plan);

    $service->checkIn($member, $subscription, null, true);
    $service->checkIn($member, $subscription, null, true);

    expect($service->usedCount($subscription))->toBe(2);
});

it('requires confirmation for duplicate same-day check-ins', function (): void {
    $service = app(PlanCheckInService::class);
    $member = Member::factory()->create(['status' => Status::Active->value]);
    $plan = Plan::factory()->withUseLimit(5)->create(['status' => Status::Active->value]);
    $subscription = createEligibleSubscription($member, $plan);

    $service->checkIn($member, $subscription);

    expect(fn () => $service->checkIn($member, $subscription))
        ->toThrow(DuplicateCheckInRequiresConfirmationException::class);

    $service->checkIn($member, $subscription, null, true);

    expect($service->usedCount($subscription))->toBe(2);
});

it('rejects inactive members', function (): void {
    $service = app(PlanCheckInService::class);
    $member = Member::factory()->create(['status' => Status::Inactive->value]);
    $plan = Plan::factory()->create(['status' => Status::Active->value]);
    $subscription = createEligibleSubscription($member, $plan);

    expect(fn () => $service->checkIn($member, $subscription))
        ->toThrow(MemberInactiveException::class);
});

it('rejects subscriptions outside the eligible window', function (): void {
    $service = app(PlanCheckInService::class);
    $member = Member::factory()->create(['status' => Status::Active->value]);
    $plan = Plan::factory()->create(['status' => Status::Active->value]);
    $today = Carbon::today(config('app.timezone'));
    $subscription = createEligibleSubscription($member, $plan, [
        'start_date' => $today->copy()->subDays(60)->toDateString(),
        'end_date' => $today->copy()->subDays(1)->toDateString(),
        'status' => Status::Expired->value,
    ]);

    expect($service->eligibleSubscriptions($member))->toBeEmpty();

    expect(fn () => $service->checkIn($member, $subscription))
        ->toThrow(SubscriptionNotEligibleException::class);
});
