<?php

use App\Enums\Status;
use App\Helpers\Helpers;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Service;
use App\Models\Subscription;
use App\Support\Membership\MembershipStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Helpers::setTestSettingsOverride(null);
});

function paymentDueSoonPlan(): Plan
{
    $service = Service::factory()->create();
    $plan = Plan::factory()->create([
        'status' => Status::Active,
        'amount' => 1000,
    ]);
    $plan->services()->attach($service);

    return $plan;
}

function paymentDueSoonMember(): Member
{
    return Member::factory()->create(['status' => Status::Active]);
}

function paymentDueSoonSubscription(Member $member, Plan $plan, string $status = 'ongoing'): Subscription
{
    return Subscription::factory()->create([
        'member_id' => $member->id,
        'plan_id' => $plan->id,
        'location_id' => Location::factory()->create()->id,
        'status' => $status,
        'start_date' => now()->subDays(5)->toDateString(),
        'end_date' => now()->addDays(25)->toDateString(),
    ]);
}

it('returns true when payment is due within the configured window', function (): void {
    Helpers::setTestSettingsOverride(['subscriptions' => ['expiring_days' => 7]]);

    $plan = paymentDueSoonPlan();
    $member = paymentDueSoonMember();
    $subscription = paymentDueSoonSubscription($member, $plan);

    Invoice::factory()->create([
        'subscription_id' => $subscription->id,
        'due_date' => now()->addDays(5)->toDateString(),
        'paid_amount' => 0,
        'status' => Status::Issued,
        'due_amount' => 1000,
        'total_amount' => 1000,
    ]);

    expect(MembershipStatus::isPaymentDueSoon($member))->toBeTrue();
});

it('treats due today as due soon', function (): void {
    Helpers::setTestSettingsOverride(['subscriptions' => ['expiring_days' => 7]]);

    $plan = paymentDueSoonPlan();
    $member = paymentDueSoonMember();
    $subscription = paymentDueSoonSubscription($member, $plan);

    Invoice::factory()->create([
        'subscription_id' => $subscription->id,
        'due_date' => now()->toDateString(),
        'paid_amount' => 0,
        'status' => Status::Issued,
        'due_amount' => 500,
        'total_amount' => 500,
    ]);

    expect(MembershipStatus::isPaymentDueSoon($member))->toBeTrue();
});

it('returns false when payment is due beyond the window', function (): void {
    Helpers::setTestSettingsOverride(['subscriptions' => ['expiring_days' => 7]]);

    $plan = paymentDueSoonPlan();
    $member = paymentDueSoonMember();
    $subscription = paymentDueSoonSubscription($member, $plan);

    Invoice::factory()->create([
        'subscription_id' => $subscription->id,
        'due_date' => now()->addDays(10)->toDateString(),
        'paid_amount' => 0,
        'status' => Status::Issued,
        'due_amount' => 500,
        'total_amount' => 500,
    ]);

    expect(MembershipStatus::isPaymentDueSoon($member))->toBeFalse();
});

it('does not count overdue invoices as due soon', function (): void {
    Helpers::setTestSettingsOverride(['subscriptions' => ['expiring_days' => 7]]);

    $plan = paymentDueSoonPlan();
    $member = paymentDueSoonMember();
    $subscription = paymentDueSoonSubscription($member, $plan);

    Invoice::factory()->create([
        'subscription_id' => $subscription->id,
        'due_date' => now()->subDay()->toDateString(),
        'paid_amount' => 0,
        'status' => Status::Issued,
        'due_amount' => 500,
        'total_amount' => 500,
    ]);

    expect(MembershipStatus::isPaymentDueSoon($member))->toBeFalse();
});

it('does not count invoices with zero due amount', function (): void {
    Helpers::setTestSettingsOverride(['subscriptions' => ['expiring_days' => 7]]);

    $plan = paymentDueSoonPlan();
    $member = paymentDueSoonMember();
    $subscription = paymentDueSoonSubscription($member, $plan);

    Invoice::factory()->create([
        'subscription_id' => $subscription->id,
        'due_date' => now()->addDays(3)->toDateString(),
        'paid_amount' => 1000,
        'status' => Status::Paid,
        'due_amount' => 0,
        'total_amount' => 1000,
    ]);

    expect(MembershipStatus::isPaymentDueSoon($member))->toBeFalse();
});

it('ignores invoices belonging to expired subscriptions', function (): void {
    Helpers::setTestSettingsOverride(['subscriptions' => ['expiring_days' => 7]]);

    $plan = paymentDueSoonPlan();
    $member = paymentDueSoonMember();

    $expired = Subscription::factory()->create([
        'member_id' => $member->id,
        'plan_id' => $plan->id,
        'location_id' => Location::factory()->create()->id,
        'status' => Status::Expired,
        'start_date' => now()->subDays(30)->toDateString(),
        'end_date' => now()->subDay()->toDateString(),
    ]);

    Invoice::factory()->create([
        'subscription_id' => $expired->id,
        'due_date' => now()->addDays(3)->toDateString(),
        'paid_amount' => 0,
        'status' => Status::Issued,
        'due_amount' => 500,
        'total_amount' => 500,
    ]);

    expect(MembershipStatus::isPaymentDueSoon($member))->toBeFalse();
});

it('respects the configured expiring_days window', function (): void {
    $plan = paymentDueSoonPlan();
    $member = paymentDueSoonMember();
    $subscription = paymentDueSoonSubscription($member, $plan);

    Invoice::factory()->create([
        'subscription_id' => $subscription->id,
        'due_date' => now()->addDays(5)->toDateString(),
        'paid_amount' => 0,
        'status' => Status::Issued,
        'due_amount' => 500,
        'total_amount' => 500,
    ]);

    Helpers::setTestSettingsOverride(['subscriptions' => ['expiring_days' => 3]]);
    expect(MembershipStatus::isPaymentDueSoon($member))->toBeFalse();

    Helpers::setTestSettingsOverride(['subscriptions' => ['expiring_days' => 7]]);
    expect(MembershipStatus::isPaymentDueSoon($member))->toBeTrue();
});

it('uses effectiveStatus so overdue is not counted as due soon', function (): void {
    Helpers::setTestSettingsOverride(['subscriptions' => ['expiring_days' => 7]]);

    $plan = paymentDueSoonPlan();
    $member = paymentDueSoonMember();
    $subscription = paymentDueSoonSubscription($member, $plan);

    $invoice = Invoice::factory()->create([
        'subscription_id' => $subscription->id,
        'due_date' => now()->subDay()->toDateString(),
        'paid_amount' => 0,
        'status' => Status::Issued,
        'due_amount' => 500,
        'total_amount' => 500,
    ]);

    expect($invoice->refresh()->effectiveStatus())->toBe(Status::Overdue)
        ->and(MembershipStatus::isPaymentDueSoon($member))->toBeFalse();
});
