<?php

use App\Enums\Status;
use App\Helpers\Helpers;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Service;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Membership\MembershipStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Helpers::setTestSettingsOverride(null);
});

it('shows warning ring when payment is due within the configured window', function (): void {
    Helpers::setTestSettingsOverride(['subscriptions' => ['expiring_days' => 7]]);

    Role::firstOrCreate(['name' => 'owner']);
    $staff = User::factory()->create();
    $staff->assignRole('owner');

    $location = Location::factory()->create();
    app(\App\Contracts\TenantContext::class)->setLocationId($location->id);

    $service = Service::factory()->create(['location_id' => $location->id]);
    $plan = Plan::factory()->create(['status' => Status::Active, 'amount' => 1000]);
    $plan->services()->attach($service->id);

    $member = Member::factory()->create(['status' => Status::Active]);

    $subscription = Subscription::factory()->create([
        'member_id' => $member->id,
        'plan_id' => $plan->id,
        'location_id' => $location->id,
        'status' => Status::Ongoing,
        'start_date' => now()->subDays(5)->toDateString(),
        'end_date' => now()->addDays(30)->toDateString(),
    ]);

    Invoice::factory()->create([
        'subscription_id' => $subscription->id,
        'due_date' => now()->addDays(3)->toDateString(),
        'paid_amount' => 0,
        'status' => Status::Issued,
        'due_amount' => 500,
        'total_amount' => 500,
    ]);

    expect(MembershipStatus::isPaymentDueSoon($member))->toBeTrue();
    expect(MembershipStatus::forMember($member)['color'])->toBe('success');

    $checkInStatus = MembershipStatus::forMember($member);
    $planRank = match ($checkInStatus['color']) {
        'danger' => 2, 'warning' => 1, default => 0,
    };
    $paymentRank = MembershipStatus::isPaymentDueSoon($member) ? 1 : 0;
    $cardRank = max($planRank, 0, $paymentRank);

    expect($cardRank)->toBe(1);
});

it('does not show warning when payment is due beyond the window', function (): void {
    Helpers::setTestSettingsOverride(['subscriptions' => ['expiring_days' => 7]]);

    $location = Location::factory()->create();
    app(\App\Contracts\TenantContext::class)->setLocationId($location->id);

    $service = Service::factory()->create(['location_id' => $location->id]);
    $plan = Plan::factory()->create(['status' => Status::Active]);
    $plan->services()->attach($service->id);

    $member = Member::factory()->create(['status' => Status::Active]);
    $subscription = Subscription::factory()->create([
        'member_id' => $member->id,
        'plan_id' => $plan->id,
        'location_id' => $location->id,
        'status' => Status::Ongoing,
        'start_date' => now()->subDays(5)->toDateString(),
        'end_date' => now()->addDays(30)->toDateString(),
    ]);

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
