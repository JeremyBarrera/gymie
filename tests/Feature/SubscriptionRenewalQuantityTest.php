<?php

use App\Models\Invoice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Subscriptions\SubscriptionRenewalService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    $this->member = Member::factory()->create([
        'status' => 'active',
    ]);

    $this->previous = Subscription::factory()->create([
        'member_id' => $this->member->id,
        'plan_id' => $this->plan->id,
        'location_id' => $this->location->id,
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'status' => 'ongoing',
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('creates chained subscriptions when renewal quantity is 2', function (): void {
    $result = app(SubscriptionRenewalService::class)->renew($this->previous, [
        'plan_id' => $this->plan->id,
        'quantity' => 2,
        'start_date' => '2026-03-15',
        'invoice' => [
            'payment_method' => 'cash',
            'paid_amount' => 20,
            'date' => '2026-03-15',
        ],
    ]);

    expect($result['subscriptions'])->toHaveCount(2)
        ->and($result['invoices'])->toHaveCount(2);

    $subs = collect($result['subscriptions'])->sortBy(fn ($s) => $s->start_date)->values();

    expect($subs[0]->start_date->toDateString())->toBe('2026-03-15')
        ->and($subs[0]->end_date->toDateString())->toBe('2026-04-14')
        ->and($subs[1]->start_date->toDateString())->toBe('2026-04-15')
        ->and($subs[1]->end_date->toDateString())->toBe('2026-05-15');

    expect((int) $subs[0]->renewed_from_subscription_id)->toBe((int) $this->previous->id)
        ->and((int) $subs[1]->renewed_from_subscription_id)->toBe((int) $subs[0]->id);

    expect($this->previous->refresh()->status)->toBe(App\Enums\Status::Renewed);

    $invoices = collect($result['invoices'])->sortBy(fn ($i) => $i->due_date)->values();

    expect((float) $invoices[0]->paid_amount)->toBe(20.0)
        ->and((float) $invoices[1]->paid_amount)->toBe(0.0)
        ->and($invoices[1]->due_date->toDateString())->toBe('2026-04-15');

    expect((int) $result['subscription']->id)->toBe((int) $subs[1]->id)
        ->and((int) $result['invoice']->id)->toBe((int) $invoices[1]->id);
});

it('creates a single subscription when renewal quantity is omitted', function (): void {
    $result = app(SubscriptionRenewalService::class)->renew($this->previous, [
        'plan_id' => $this->plan->id,
        'start_date' => '2026-03-15',
        'invoice' => [
            'payment_method' => 'cash',
            'paid_amount' => 20,
            'date' => '2026-03-15',
        ],
    ]);

    expect($result['subscriptions'])->toHaveCount(1)
        ->and($result['invoices'])->toHaveCount(1);
});
