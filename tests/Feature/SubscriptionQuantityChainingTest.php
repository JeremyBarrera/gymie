<?php

use App\Enums\Status;
use App\Helpers\Helpers;
use App\Models\Location;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Subscriptions\MemberSubscriptionService;
use App\Services\Subscriptions\SubscriptionChainService;
use App\Services\Subscriptions\SubscriptionRenewalService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-03-15 12:00:00'));

    Role::findOrCreate('owner', 'web');

    $this->location = Location::create(['name' => 'Main']);

    $this->plan = Plan::factory()->create([
        'location_id' => $this->location->id,
        'days' => 30,
        'amount' => 50.00,
        'status' => 'active',
    ]);

    $this->evergreenPlan = Plan::factory()->create([
        'location_id' => $this->location->id,
        'days' => null,
        'amount' => 30.00,
        'status' => 'active',
    ]);

    $this->member = Member::factory()->create([
        'status' => 'active',
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('creates 3 chained subscriptions when quantity is 3', function (): void {
    $results = MemberSubscriptionService::createForMember($this->member, [
        [
            'plan_id' => $this->plan->id,
            'quantity' => 3,
            'start_date' => '2026-03-15',
            'paid_amount' => 40,
        ],
    ]);

    expect($results)->toHaveCount(3);

    $subs = Subscription::where('member_id', $this->member->id)
        ->where('plan_id', $this->plan->id)
        ->orderBy('start_date')
        ->get();

    expect($subs)->toHaveCount(3);

    expect($subs[0]->start_date->toDateString())->toBe('2026-03-15')
        ->and($subs[0]->end_date->toDateString())->toBe('2026-04-14');

    expect($subs[1]->start_date->toDateString())->toBe('2026-04-15')
        ->and($subs[1]->end_date->toDateString())->toBe('2026-05-15');

    expect($subs[2]->start_date->toDateString())->toBe('2026-05-16')
        ->and($subs[2]->end_date->toDateString())->toBe('2026-06-15');

    $invoices = \App\Models\Invoice::whereIn('subscription_id', $subs->pluck('id'))->get();
    expect($invoices)->toHaveCount(3);

    $firstInvoice = $invoices->firstWhere('subscription_id', $subs[0]->id);
    expect($firstInvoice->paid_amount)->toBeGreaterThan(0);

    $secondInvoice = $invoices->firstWhere('subscription_id', $subs[1]->id);
    expect($secondInvoice->paid_amount)->toBe(0.0);
});

it('forces quantity to 1 for evergreen plans', function (): void {
    $results = MemberSubscriptionService::createForMember($this->member, [
        [
            'plan_id' => $this->evergreenPlan->id,
            'quantity' => 5,
            'start_date' => '2026-03-15',
        ],
    ]);

    expect($results)->toHaveCount(1);

    $subs = Subscription::where('member_id', $this->member->id)
        ->where('plan_id', $this->evergreenPlan->id)
        ->get();

    expect($subs)->toHaveCount(1)
        ->and($subs[0]->end_date)->toBeNull()
        ->and($subs[0]->start_date->toDateString())->toBe('2026-03-15');
});

it('rejects overlapping start_date within the chain', function (): void {
    Subscription::create([
        'member_id' => $this->member->id,
        'plan_id' => $this->plan->id,
        'start_date' => '2026-03-01',
        'end_date' => '2026-03-31',
        'status' => 'ongoing',
        'location_id' => $this->location->id,
    ]);

    $this->expectException(ValidationException::class);
    $this->expectExceptionMessage(__('app.validation.subscription_overlap', ['end' => '2026-03-31']));

    MemberSubscriptionService::createForMember($this->member, [
        [
            'plan_id' => $this->plan->id,
            'quantity' => 2,
            'start_date' => '2026-03-15',
        ],
    ]);
});

it('accepts a valid later start_date that avoids overlaps', function (): void {
    Subscription::create([
        'member_id' => $this->member->id,
        'plan_id' => $this->plan->id,
        'start_date' => '2026-01-01',
        'end_date' => '2026-02-01',
        'status' => 'expired',
        'location_id' => $this->location->id,
    ]);

    $results = MemberSubscriptionService::createForMember($this->member, [
        [
            'plan_id' => $this->plan->id,
            'quantity' => 2,
            'start_date' => '2026-03-15',
        ],
    ]);

    expect($results)->toHaveCount(2);

    $subs = Subscription::where('member_id', $this->member->id)
        ->where('plan_id', $this->plan->id)
        ->orderBy('start_date')
        ->get();

    expect($subs)->toHaveCount(3);
    expect($subs[1]->start_date->toDateString())->toBe('2026-03-15')
        ->and($subs[2]->start_date->toDateString())->toBe('2026-04-15');
});

it('renewal uses SubscriptionChainService::nextStartDate for start date', function (): void {
    $existing = Subscription::create([
        'member_id' => $this->member->id,
        'plan_id' => $this->plan->id,
        'start_date' => '2026-01-15',
        'end_date' => '2026-02-14',
        'status' => 'ongoing',
        'location_id' => $this->location->id,
    ]);

    $renewalService = new SubscriptionRenewalService();

    $result = $renewalService->renew($existing, [
        'plan_id' => $this->plan->id,
        'invoice' => [
            'paid_amount' => 50,
            'payment_method' => 'cash',
        ],
    ]);

    $newSub = $result['subscription'];

    expect($newSub->start_date->toDateString())->toBe('2026-02-15')
        ->and($newSub->end_date->toDateString())->toBe('2026-03-17')
        ->and($newSub->renewed_from_subscription_id)->toBe($existing->id);
});

it('uses nextStartDate when no start_date is provided', function (): void {
    Subscription::create([
        'member_id' => $this->member->id,
        'plan_id' => $this->plan->id,
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'status' => 'ongoing',
        'location_id' => $this->location->id,
    ]);

    $results = MemberSubscriptionService::createForMember($this->member, [
        [
            'plan_id' => $this->plan->id,
            'quantity' => 1,
        ],
    ]);

    expect($results)->toHaveCount(1);

    $sub = Subscription::where('member_id', $this->member->id)
        ->where('plan_id', $this->plan->id)
        ->latest('start_date')
        ->first();

    expect($sub->start_date->toDateString())->toBe('2026-02-01');
});

it('chainOverlaps detects conflicts across chained rows', function (): void {
    Subscription::create([
        'member_id' => $this->member->id,
        'plan_id' => $this->plan->id,
        'start_date' => '2026-04-10',
        'end_date' => '2026-05-10',
        'status' => 'ongoing',
        'location_id' => $this->location->id,
    ]);

    $conflict = SubscriptionChainService::chainOverlaps(
        $this->member,
        $this->plan,
        '2026-03-15',
        3
    );

    expect($conflict)->not->toBeNull()
        ->and($conflict->start_date->toDateString())->toBe('2026-04-10');
});

it('chainOverlaps returns null when no conflicts exist', function (): void {
    $conflict = SubscriptionChainService::chainOverlaps(
        $this->member,
        $this->plan,
        '2026-03-15',
        3
    );

    expect($conflict)->toBeNull();
});

it('nextStartDate returns today when no existing subscriptions', function (): void {
    $startDate = SubscriptionChainService::nextStartDate($this->member, $this->plan);

    expect($startDate)->toBe('2026-03-15');
});

it('nextStartDate returns day after latest end_date for existing subscriptions', function (): void {
    Subscription::create([
        'member_id' => $this->member->id,
        'plan_id' => $this->plan->id,
        'start_date' => '2026-01-01',
        'end_date' => '2026-02-28',
        'status' => 'ongoing',
        'location_id' => $this->location->id,
    ]);

    $startDate = SubscriptionChainService::nextStartDate($this->member, $this->plan);

    expect($startDate)->toBe('2026-03-01');
});

it('only applies discount and paid_amount on the first subscription in a chain', function (): void {
    $results = MemberSubscriptionService::createForMember($this->member, [
        [
            'plan_id' => $this->plan->id,
            'quantity' => 2,
            'start_date' => '2026-03-15',
            'discount_amount' => 10,
            'paid_amount' => 40,
        ],
    ]);

    expect($results)->toHaveCount(2);

    $firstInvoice = $results[0][1];
    expect($firstInvoice->discount_amount)->toBe(10.0)
        ->and($firstInvoice->paid_amount)->toBe(40.0);

    $secondInvoice = $results[1][1];
    expect($secondInvoice->discount_amount)->toBe(0.0)
        ->and($secondInvoice->paid_amount)->toBe(0.0);
});
