<?php

use App\Enums\Status;
use App\Filament\Concerns\HandlesCheckInVerification;
use App\Filament\Livewire\AddPaymentModal;
use App\Filament\Livewire\ChangeDueDateModal;
use App\Filament\Livewire\ExpiredSubscriptionModal;
use App\Filament\Pages\Reception;
use App\Helpers\Helpers;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Location;
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

afterEach(function (): void {
    Helpers::setTestSettingsOverride(null);
});

function o3o4o5Staff(): User
{
    $staff = User::factory()->create();
    $staff->assignRole('owner');

    return $staff;
}

function o3o4o5Plan(): Plan
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

function o3o4o5Member(array $overrides = []): Member
{
    return Member::factory()->create(array_merge([
        'status' => Status::Active,
        'contact' => '5559876543',
    ], $overrides));
}

function o3o4o5ExpiredSubscription(Member $member, Plan $plan): Subscription
{
    return Subscription::factory()->create([
        'member_id' => $member->id,
        'plan_id' => $plan->id,
        'status' => Status::Expired,
        'start_date' => now()->subDays(35)->toDateString(),
        'end_date' => now()->subDays(5)->toDateString(),
    ]);
}

function o3o4o5Entry(Location $location, Member $member): QueueEntry
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

function o3o4o5ConfigureRecipients(User $pinned): void
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

// ---------------------------------------------------------------------------
// O3 — Expired path: renewal popup replaces the Override button
// ---------------------------------------------------------------------------

it('swaps the override button for add-new-subscription when the selected service is expired', function (): void {
    $location = Location::factory()->create();
    $plan = o3o4o5Plan();
    $member = o3o4o5Member();
    o3o4o5ExpiredSubscription($member, $plan);
    $entry = o3o4o5Entry($location, $member);

    $html = Livewire::actingAs(o3o4o5Staff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->call('selectCheckInService', (int) $plan->primaryService()->id)
        ->html();

    expect($html)->toContain('openExpiredSubscriptionModal')
        ->and($html)->toContain(__('app.check_in.add_subscription'))
        ->not->toContain('openCheckInOverrideFor')
        ->not->toContain('openAddPaymentModal');
});

it('opens the renewal popup preselecting the expired plan with cash and blank dates', function (): void {
    $location = Location::factory()->create();
    $plan = o3o4o5Plan();
    $member = o3o4o5Member();
    $previous = o3o4o5ExpiredSubscription($member, $plan);
    $entry = o3o4o5Entry($location, $member);

    Livewire::actingAs(o3o4o5Staff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->call('openExpiredSubscriptionModal', (int) $plan->primaryService()->id)
        ->assertDispatched('open-expired-subscription-modal',
            memberId: (int) $member->id,
            serviceId: (int) $plan->primaryService()->id,
            previousSubscriptionId: (int) $previous->id,
        );

    Livewire::actingAs(o3o4o5Staff())
        ->test(ExpiredSubscriptionModal::class)
        ->call('open', $member->id, (int) $plan->primaryService()->id, $previous->id)
        ->assertSet('planId', (int) $previous->plan_id)
        ->assertSet('paymentMethod', 'cash')
        ->assertSet('startDate', null)
        ->assertSet('endDate', null)
        ->assertSet('discountAmount', null)
        ->assertSet('paidAmount', null)
        ->call('submit')
        ->assertHasErrors(['startDate']);
});

it('renews via SubscriptionRenewalService and completes a normal check-in when fully paid', function (): void {
    Feature::activate('checkin.override');

    $location = Location::factory()->create();
    $plan = o3o4o5Plan();
    $member = o3o4o5Member();
    $previous = o3o4o5ExpiredSubscription($member, $plan);
    $entry = o3o4o5Entry($location, $member);
    $startDate = now()->toDateString();

    Livewire::actingAs(o3o4o5Staff())
        ->test(ExpiredSubscriptionModal::class)
        ->call('open', $member->id, (int) $plan->primaryService()->id, $previous->id)
        ->set('startDate', $startDate)
        ->set('paidAmount', 100)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDispatched('close-modal', id: 'expired-subscription-modal')
        ->assertDispatched('check-in.resolved-by-modal', function (string $_, array $params): bool {
            return isset($params['subscriptionId']) && $params['subscriptionId'] > 0;
        });

    $renewal = Subscription::query()->whereKeyNot($previous->id)->where('member_id', $member->id)->first();

    expect($renewal)->not->toBeNull()
        ->and((int) $renewal->renewed_from_subscription_id)->toBe((int) $previous->id)
        ->and($renewal->status)->toBe(Status::Ongoing)
        ->and($previous->refresh()->status)->toBe(Status::Renewed)
        ->and($renewal->start_date->format('Y-m-d'))->toBe($startDate)
        ->and($renewal->invoices()->count())->toBe(1)
        ->and((float) $renewal->invoices()->first()->due_amount)->toBe(0.0)
        // Fully paid: no follow-up alert anywhere.
        ->and(User::query()->get()->sum(fn (User $u) => $u->unreadNotifications()->count()))->toBe(0);

    Livewire::actingAs(o3o4o5Staff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->set('checkInServiceId', (int) $plan->primaryService()->id)
        ->call('completeResolvedCheckIn', (int) $renewal->id)
        ->assertDispatched('notify')
        ->assertSet('showCheckInOverlay', false);

    $checkIn = PlanCheckIn::query()->first();

    expect($checkIn)->not->toBeNull()
        ->and($checkIn->subscription_id)->toBe((int) $renewal->id)
        ->and($checkIn->override)->toBeFalse()
        ->and($entry->refresh()->status)->toBe('approved');
});

it('fires the new_subscription follow-up alert when the renewed invoice is left unpaid', function (): void {
    $pinned = User::factory()->create();
    o3o4o5ConfigureRecipients($pinned);

    $plan = o3o4o5Plan();
    $member = o3o4o5Member();
    $previous = o3o4o5ExpiredSubscription($member, $plan);

    Livewire::actingAs(o3o4o5Staff())
        ->test(ExpiredSubscriptionModal::class)
        ->call('open', $member->id, (int) $plan->primaryService()->id, $previous->id)
        ->set('startDate', now()->toDateString())
        ->set('discountAmount', 10)
        ->set('paidAmount', 0)
        ->call('submit')
        ->assertHasNoErrors();

    $renewal = Subscription::query()->whereKeyNot($previous->id)->where('member_id', $member->id)->first();
    $invoice = $renewal->invoices()->first();

    /** @var array<string, mixed> $payload */
    $payload = $pinned->unreadNotifications()->first()->data;

    expect($payload['action'])->toBe('new_subscription')
        ->and($payload['subscription_id'])->toBe((int) $renewal->id)
        ->and($payload['invoice_id'])->toBe((int) $invoice->id)
        ->and($payload['reason'])->toContain(Helpers::formatCurrency((float) $invoice->due_amount))
        ->and((float) $invoice->due_amount)->toBeGreaterThan(0);
});

it('refuses to renew the same expired subscription twice', function (): void {
    $plan = o3o4o5Plan();
    $member = o3o4o5Member();
    $previous = o3o4o5ExpiredSubscription($member, $plan);

    $component = Livewire::actingAs(o3o4o5Staff())
        ->test(ExpiredSubscriptionModal::class)
        ->call('open', $member->id, (int) $plan->primaryService()->id, $previous->id)
        ->set('startDate', now()->toDateString());

    $component->call('submit')->assertHasNoErrors();

    $countAfterFirst = Subscription::query()->where('member_id', $member->id)->count();
    expect($countAfterFirst)->toBe(2);

    $component->call('open', $member->id, (int) $plan->primaryService()->id, $previous->id)
        ->set('startDate', now()->toDateString())
        ->call('submit')
        ->assertDispatched('notify');

    expect(Subscription::query()->where('member_id', $member->id)->count())->toBe($countAfterFirst);
});

it('blocks the renewal popup behind billing permissions', function (): void {
    Role::firstOrCreate(['name' => 'desk']);
    $limited = User::factory()->create();
    $limited->assignRole('desk');
    Feature::activate('checkin.override');

    $plan = o3o4o5Plan();
    $member = o3o4o5Member();
    $previous = o3o4o5ExpiredSubscription($member, $plan);

    expect(Subscription::query()->where('member_id', $member->id)->count())->toBe(1);

    Livewire::actingAs($limited)
        ->test(ExpiredSubscriptionModal::class)
        ->call('open', $member->id, (int) $plan->primaryService()->id, $previous->id)
        ->set('startDate', now()->toDateString())
        ->set('paidAmount', 100)
        ->call('submit');

    expect(Subscription::query()->where('member_id', $member->id)->count())->toBe(1)
        ->and($previous->refresh()->status)->toBe(Status::Expired);
});

// ---------------------------------------------------------------------------
// O4 — No-access path: optional-message override with follow-up alert
// ---------------------------------------------------------------------------

it('sends an override_checkin alert with the system default reason when the message is blank', function (): void {
    Feature::activate('checkin.override');

    $staff = o3o4o5Staff();
    $pinned = User::factory()->create();
    o3o4o5ConfigureRecipients($pinned);

    $location = Location::factory()->create();
    $plan = o3o4o5Plan();
    $member = o3o4o5Member();
    $entry = o3o4o5Entry($location, $member);

    Livewire::actingAs($staff)
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->set('checkInServiceId', (int) $plan->primaryService()->id)
        ->call('openCheckInOverrideFor', (int) $plan->primaryService()->id)
        ->assertSet('checkInOverrideStep', true)
        ->call('confirmCheckInOverride')
        ->assertSet('showCheckInOverlay', false);

    $checkIn = PlanCheckIn::query()->first();

    /** @var array<string, mixed> $payload */
    $payload = $pinned->unreadNotifications()->first()->data;

    expect($checkIn->override)->toBeTrue()
        ->and($checkIn->override_reason)->toBe('no_subscription')
        ->and($payload['action'])->toBe('override_checkin')
        ->and($payload['reason'])->toBe('no_subscription')
        ->and($payload['actor']['id'])->toBe((int) $staff->id);
});

it('passes the typed optional message as the override_checkin alert reason', function (): void {
    Feature::activate('checkin.override');

    $staff = o3o4o5Staff();
    $pinned = User::factory()->create();
    o3o4o5ConfigureRecipients($pinned);

    $location = Location::factory()->create();
    $plan = o3o4o5Plan();
    $member = o3o4o5Member();
    $entry = o3o4o5Entry($location, $member);

    Livewire::actingAs($staff)
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->set('checkInServiceId', (int) $plan->primaryService()->id)
        ->call('openCheckInOverrideFor', (int) $plan->primaryService()->id)
        ->assertSet('checkInOverrideStep', true)
        ->set('checkInOverrideReason', 'Came in for physio recovery session')
        ->call('confirmCheckInOverride')
        ->assertSet('showCheckInOverlay', false);

    $checkIn = PlanCheckIn::query()->first();

    /** @var array<string, mixed> $payload */
    $payload = $pinned->unreadNotifications()->first()->data;

    expect($payload['action'])->toBe('override_checkin')
        ->and($payload['reason'])->toBe('Came in for physio recovery session')
        ->and($checkIn->override_reason)->toBe('Came in for physio recovery session')
        ->and($entry->refresh()->status)->toBe('approved');
});

it('keeps the generic override step exclusive to no-access rows', function (): void {
    Feature::activate('checkin.override');

    $location = Location::factory()->create();
    $plan = o3o4o5Plan();
    $member = o3o4o5Member();
    $expired = o3o4o5ExpiredSubscription($member, $plan);
    $entry = o3o4o5Entry($location, $member);

    Livewire::actingAs(o3o4o5Staff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->set('checkInServiceId', (int) $plan->primaryService()->id)
        ->call('openCheckInOverrideFor', (int) $plan->primaryService()->id)
        ->assertSet('checkInOverrideStep', false);

    expect(PlanCheckIn::count())->toBe(0)
        ->and($expired->refresh()->status)->toBe(Status::Expired);
});

// ---------------------------------------------------------------------------
// O5 — Past-due split: Add payment / Change due date
// ---------------------------------------------------------------------------

it('shows two past-due buttons instead of the generic override and resolves the blocking invoice', function (): void {
    $location = Location::factory()->create();
    $plan = o3o4o5Plan();
    $member = o3o4o5Member();
    $subscription = Subscription::factory()->create([
        'member_id' => $member->id,
        'plan_id' => $plan->id,
        'status' => Status::Ongoing,
        'start_date' => now()->subDays(5)->toDateString(),
        'end_date' => now()->addDays(25)->toDateString(),
    ]);

    Invoice::factory()->create([
        'subscription_id' => $subscription->id,
        'status' => Status::Issued,
        'subscription_fee' => 100,
        'discount' => 0,
        'discount_amount' => 0,
        'paid_amount' => 0,
        'due_date' => now()->subDay(),
    ]);

    $entry = o3o4o5Entry($location, $member);

    $component = Livewire::actingAs(o3o4o5Staff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->call('selectCheckInService', (int) $plan->primaryService()->id);

    $html = $component->html();

    expect($html)->toContain('openAddPaymentModal')
        ->and($html)->toContain('openChangeDueDateModal')
        ->not->toContain('openCheckInOverrideFor');

    $component->call('openAddPaymentModal', (int) $plan->primaryService()->id)
        ->assertDispatched('open-add-payment-modal', function (string $_, array $params): bool {
            return (int) $params['invoiceId'] > 0 && $params['subscriptionId'] !== null;
        })
        ->call('openChangeDueDateModal', (int) $plan->primaryService()->id)
        ->assertDispatched('open-change-due-date-modal');
});

it('settles the balance in full, records one payment and completes a normal check-in', function (): void {
    $location = Location::factory()->create();
    $plan = o3o4o5Plan();
    $member = o3o4o5Member();
    $subscription = Subscription::factory()->create([
        'member_id' => $member->id,
        'plan_id' => $plan->id,
        'status' => Status::Ongoing,
        'start_date' => now()->subDays(5)->toDateString(),
        'end_date' => now()->addDays(25)->toDateString(),
    ]);

    $invoice = Invoice::factory()->create([
        'subscription_id' => $subscription->id,
        'status' => Status::Overdue,
        'subscription_fee' => 100,
        'discount' => 0,
        'discount_amount' => 0,
        'paid_amount' => 0,
        'due_date' => now()->subDay(),
    ]);

    $entry = o3o4o5Entry($location, $member);

    Livewire::actingAs(o3o4o5Staff())
        ->test(AddPaymentModal::class)
        ->call('open', $member->id, $invoice->id, (int) $plan->primaryService()->id, (int) $subscription->id)
        ->assertSet('amount', 100.0)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDispatched('close-modal', id: 'add-payment-modal')
        ->assertDispatched('check-in.resolved-by-modal', fn (string $_, array $params): bool => (int) $params['subscriptionId'] === (int) $subscription->id);

    $invoice->refresh();

    expect($invoice->status->value)->toBe('paid')
        ->and((float) $invoice->due_amount)->toBe(0.0)
        ->and(InvoiceTransaction::query()->where('invoice_id', $invoice->id)->where('type', 'payment')->count())->toBe(1)
        ->and(User::query()->get()->sum(fn (User $u) => $u->unreadNotifications()->count()))->toBe(0);

    Livewire::actingAs(o3o4o5Staff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->set('checkInServiceId', (int) $plan->primaryService()->id)
        ->call('completeResolvedCheckIn', (int) $subscription->id)
        ->assertSet('showCheckInOverlay', false);

    $checkIn = PlanCheckIn::query()->first();

    expect($checkIn->override)->toBeFalse()
        ->and($entry->refresh()->status)->toBe('approved');
});

it('requires a future next-payment date for a partial balance and fires payment_added', function (): void {
    Feature::activate('checkin.override');

    $pinned = User::factory()->create();
    o3o4o5ConfigureRecipients($pinned);

    $plan = o3o4o5Plan();
    $member = o3o4o5Member();
    $subscription = Subscription::factory()->create([
        'member_id' => $member->id,
        'plan_id' => $plan->id,
        'status' => Status::Ongoing,
        'start_date' => now()->subDays(5)->toDateString(),
        'end_date' => now()->addDays(25)->toDateString(),
    ]);

    $invoice = Invoice::factory()->create([
        'subscription_id' => $subscription->id,
        'status' => Status::Overdue,
        'subscription_fee' => 100,
        'discount' => 0,
        'discount_amount' => 0,
        'paid_amount' => 0,
        'due_date' => now()->subDay(),
    ]);

    $nextDue = now()->addWeek()->format('Y-m-d');

    $entry = o3o4o5Entry(Location::factory()->create(), $member);

    $component = Livewire::actingAs(o3o4o5Staff())
        ->test(AddPaymentModal::class)
        ->call('open', $member->id, $invoice->id, (int) $plan->primaryService()->id, (int) $subscription->id)
        ->set('amount', 40)
        ->call('submit');

    $component->assertHasErrors(['nextDueDate']);

    $component->set('nextDueDate', now()->toDateString())
        ->call('submit')
        ->assertHasErrors(['nextDueDate']);

    $component->set('reason', 'Pays the rest next week')
        ->set('nextDueDate', $nextDue)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDispatched('close-modal', id: 'add-payment-modal')
        ->assertDispatched('check-in.assisted-override', function (string $_, array $params): bool {
            return (int) $params['serviceId'] > 0
                && $params['reason'] === 'Pays the rest next week';
        });

    $invoice->refresh();

    expect($invoice->status->value)->toBe('partial')
        ->and((float) $invoice->paid_amount)->toBe(40.0)
        ->and((float) $invoice->due_amount)->toBe(60.0)
        ->and($invoice->due_date->format('Y-m-d'))->toBe($nextDue);

    /** @var array<string, mixed> $payload */
    $payload = $pinned->unreadNotifications()->first()->data;

    expect($payload['action'])->toBe('payment_added')
        ->and($payload['invoice_id'])->toBe((int) $invoice->id)
        ->and($payload['reason'])->toContain(Helpers::formatCurrency(60.0));

    Livewire::actingAs(o3o4o5Staff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->set('checkInServiceId', (int) $plan->primaryService()->id)
        ->call('completeAssistedOverrideCheckIn', (int) $plan->primaryService()->id, 'Pays the rest next week');

    $checkIn = PlanCheckIn::query()->first();

    expect($checkIn->override)->toBeTrue()
        ->and($checkIn->override_reason)->toBe('Pays the rest next week')
        ->and($entry->refresh()->status)->toBe('approved');
});

it('changes only the due date, fires due_date_changed and completes the override check-in', function (): void {
    Feature::activate('checkin.override');

    $pinned = User::factory()->create();
    o3o4o5ConfigureRecipients($pinned);

    $plan = o3o4o5Plan();
    $member = o3o4o5Member();
    $subscription = Subscription::factory()->create([
        'member_id' => $member->id,
        'plan_id' => $plan->id,
        'status' => Status::Ongoing,
        'start_date' => now()->subDays(5)->toDateString(),
        'end_date' => now()->addDays(25)->toDateString(),
    ]);

    $invoice = Invoice::factory()->create([
        'subscription_id' => $subscription->id,
        'status' => Status::Overdue,
        'subscription_fee' => 100,
        'discount' => 0,
        'discount_amount' => 0,
        'paid_amount' => 0,
        'due_date' => now()->subDay(),
    ]);

    $newDue = now()->addWeeks(2)->format('Y-m-d');
    $before = $invoice->only(['paid_amount', 'total_amount', 'subscription_fee']);
    $entry = o3o4o5Entry(Location::factory()->create(), $member);

    $component = Livewire::actingAs(o3o4o5Staff())
        ->test(ChangeDueDateModal::class)
        ->call('open', $member->id, $invoice->id, (int) $plan->primaryService()->id, (int) $subscription->id)
        ->assertSet('newDueDate', null);

    expect($component->get('canConfirm'))->toBeFalse();

    $component->set('newDueDate', now()->toDateString());

    expect($component->get('canConfirm'))->toBeFalse();

    $component->set('newDueDate', $newDue);

    expect($component->get('canConfirm'))->toBeTrue();

    $component->call('confirm')
        ->assertHasNoErrors()
        ->assertDispatched('close-modal', id: 'change-due-date-modal')
        ->assertDispatched('check-in.assisted-override', function (string $_, array $params): bool {
            return $params['reason'] === __('app.check_in.due_date_updated');
        });

    $invoice->refresh();

    expect($invoice->due_date->format('Y-m-d'))->toBe($newDue)
        ->and($invoice->only(['paid_amount', 'total_amount', 'subscription_fee']))->toBe($before)
        // Only the due date moved: with a future due date and nothing paid,
        // the invoice reads as plain issued again (no longer overdue).
        ->and($invoice->status->value)->toBe('issued');

    /** @var array<string, mixed> $payload */
    $payload = $pinned->unreadNotifications()->first()->data;

    expect($payload['action'])->toBe('due_date_changed')
        ->and($payload['invoice_id'])->toBe((int) $invoice->id);

    Livewire::actingAs(o3o4o5Staff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->set('checkInServiceId', (int) $plan->primaryService()->id)
        ->call('completeAssistedOverrideCheckIn', (int) $plan->primaryService()->id, __('app.check_in.due_date_updated'));

    $checkIn = PlanCheckIn::query()->first();

    expect($checkIn->override)->toBeTrue()
        ->and($checkIn->override_reason)->toBe(__('app.check_in.due_date_updated'))
        ->and($entry->refresh()->status)->toBe('approved');
});

it('blocks payment recording behind invoice update permissions', function (): void {
    Role::firstOrCreate(['name' => 'desk']);
    $limited = User::factory()->create();
    $limited->assignRole('desk');
    Feature::activate('checkin.override');

    $plan = o3o4o5Plan();
    $member = o3o4o5Member();
    $subscription = Subscription::factory()->create([
        'member_id' => $member->id,
        'plan_id' => $plan->id,
        'status' => Status::Ongoing,
        'start_date' => now()->subDays(5)->toDateString(),
        'end_date' => now()->addDays(25)->toDateString(),
    ]);

    $invoice = Invoice::factory()->create([
        'subscription_id' => $subscription->id,
        'status' => Status::Overdue,
        'subscription_fee' => 100,
        'discount' => 0,
        'discount_amount' => 0,
        'paid_amount' => 0,
        'due_date' => now()->subDay(),
    ]);

    Livewire::actingAs($limited)
        ->test(AddPaymentModal::class)
        ->call('open', $member->id, $invoice->id, (int) $plan->primaryService()->id, (int) $subscription->id)
        ->call('submit')
        ->assertDispatched('notify');

    $invoice->refresh();

    expect(InvoiceTransaction::query()->where('invoice_id', $invoice->id)->count())->toBe(0)
        ->and((float) $invoice->due_amount)->toBe(100.0)
        ->and(PlanCheckIn::count())->toBe(0);
});

it('removed the forced due-date detour outright (properties, actions and markup)', function (): void {
    expect(method_exists(Reception::class, 'confirmDueDateChange'))->toBeFalse()
        ->and(method_exists(Reception::class, 'cancelDueDateChange'))->toBeFalse()
        ->and(property_exists(Reception::class, 'checkInOverrideDueDateStep'))->toBeFalse()
        ->and(property_exists(Reception::class, 'checkInOverrideNewDueDate'))->toBeFalse()
        ->and(class_uses_recursive(Reception::class))->toContain(HandlesCheckInVerification::class);

    $location = Location::factory()->create();
    $plan = o3o4o5Plan();
    $member = o3o4o5Member();
    $subscription = Subscription::factory()->create([
        'member_id' => $member->id,
        'plan_id' => $plan->id,
        'status' => Status::Ongoing,
        'start_date' => now()->subDays(5)->toDateString(),
        'end_date' => now()->addDays(25)->toDateString(),
    ]);

    Invoice::factory()->create([
        'subscription_id' => $subscription->id,
        'status' => Status::Overdue,
        'subscription_fee' => 100,
        'discount' => 0,
        'discount_amount' => 0,
        'paid_amount' => 0,
        'due_date' => now()->subDay(),
    ]);

    $entry = o3o4o5Entry($location, $member);

    $html = Livewire::actingAs(o3o4o5Staff())
        ->test(Reception::class)
        ->call('onQueueEntryCreated', ['queueEntryId' => $entry->id])
        ->call('selectCheckInService', (int) $plan->primaryService()->id)
        ->html();

    expect($html)->not->toContain('confirmDueDateChange')
        ->and($html)->not->toContain('checkInOverrideNewDueDate');
});
