<?php

use App\Console\Commands\MarkSubscriptionsStatus;
use App\Enums\Status;
use App\Models\Member;
use App\Models\Plan;
use App\Models\PlanCheckIn;
use App\Models\Service;
use App\Models\Subscription;
use App\Services\Membership\PlanCheckInService;
use App\Services\Subscriptions\SubscriptionRenewalService;
use App\Support\Membership\MembershipStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function evergreenPlan(): Plan
{
    $plan = Plan::factory()->create([
        'days' => null,
        'status' => Status::Active,
    ]);
    $plan->services()->attach(Service::factory()->create());

    return $plan;
}

function evergreenSubscription(Member $member, Plan $plan): Subscription
{
    return Subscription::factory()->create([
        'member_id' => $member->id,
        'plan_id' => $plan->id,
        'start_date' => now()->subDays(30)->toDateString(),
        'end_date' => null,
        'status' => Status::Ongoing,
    ]);
}

it('treats a subscription without an end date as always eligible', function (): void {
    $plan = evergreenPlan();
    $member = Member::factory()->create(['status' => Status::Active]);
    evergreenSubscription($member, $plan);

    $eligible = app(PlanCheckInService::class)->eligibleSubscriptions($member);

    expect($eligible)->toHaveCount(1)
        ->and($eligible->first()->end_date)->toBeNull();
});

it('counts uses for an evergreen subscription across its whole lifetime', function (): void {
    $service = Service::factory()->create();
    $plan = evergreenPlan();
    $plan->services()->attach($service->id);
    $member = Member::factory()->create(['status' => Status::Active]);
    $subscription = evergreenSubscription($member, $plan);

    foreach ([20, 1] as $daysAgo) {
        PlanCheckIn::create([
            'member_id' => $member->id,
            'subscription_id' => $subscription->id,
            'service_id' => $service->id,
            'checked_in_at' => now()->subDays($daysAgo),
        ]);
    }

    expect(app(PlanCheckInService::class)->usedCount($subscription))->toBe(2);
});

it('renews an evergreen subscription into another evergreen subscription', function (): void {
    $plan = evergreenPlan();
    $member = Member::factory()->create(['status' => Status::Active]);
    $old = evergreenSubscription($member, $plan);
    $old->update(['status' => Status::Expired]);

    $result = app(SubscriptionRenewalService::class)->renew($old, [
        'plan_id' => $plan->id,
        'start_date' => now()->toDateString(),
        'payment_method' => 'cash',
    ]);

    expect($result['subscription']->end_date)->toBeNull()
        ->and($result['subscription']->status)->toBe(Status::Ongoing);
});

it('never marks an evergreen subscription expired or expiring', function (): void {
    $plan = evergreenPlan();
    $member = Member::factory()->create(['status' => Status::Active]);
    evergreenSubscription($member, $plan);

    $this->artisan(MarkSubscriptionsStatus::class);

    expect($member->refresh()->subscriptions()->first()->status)->toBe(Status::Ongoing);
});

it('prefers the evergreen subscription when showing the member badge', function (): void {
    $plan = evergreenPlan();
    $member = Member::factory()->create(['status' => Status::Active]);

    Subscription::factory()->create([
        'member_id' => $member->id,
        'plan_id' => $plan->id,
        'start_date' => now()->subDays(40)->toDateString(),
        'end_date' => now()->subDays(10)->toDateString(),
        'status' => Status::Expired,
    ]);
    evergreenSubscription($member, $plan);

    $badge = MembershipStatus::forMember($member);

    expect($badge['color'])->toBe('success')
        ->and($badge['label'])->toBe(__('app.membership_status.valid'));
});
