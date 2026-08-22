<?php

use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Models\Invoice;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'owner']);
    config()->set('app.timezone', 'UTC');
    Carbon::setTestNow(Carbon::parse('2026-03-10 10:00:00', 'UTC'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function createInvoiceWithStatus(string $status, string $dueDate, float $dueAmount): Invoice
{
    $member = Member::factory()->create();
    $plan = Plan::factory()->create(['amount' => 1000, 'days' => 30]);
    $subscription = Subscription::factory()->create([
        'member_id' => $member->id,
        'plan_id' => $plan->id,
        'start_date' => '2026-02-01',
        'end_date' => '2026-03-01',
    ]);

    return Invoice::withoutEvents(fn (): Invoice => Invoice::factory()->create([
        'subscription_id' => $subscription->id,
        'subscription_fee' => 1000,
        'discount_amount' => 0,
        'paid_amount' => 1000 - $dueAmount,
        'due_amount' => $dueAmount,
        'total_amount' => 1000,
        'date' => '2026-02-01',
        'due_date' => $dueDate,
        'status' => $status,
    ]));
}

it('marks issued and partial invoices overdue once their due date passes', function (): void {
    $issued = createInvoiceWithStatus('issued', '2026-03-15', 500);
    $partial = createInvoiceWithStatus('partial', '2026-03-15', 300);

    Carbon::setTestNow(Carbon::parse('2026-03-20 10:00:00', 'UTC'));

    $updated = Invoice::markOverdue();

    expect($updated)->toBe(2);
    expect($issued->refresh()->status?->value)->toBe('overdue');
    expect($partial->refresh()->status?->value)->toBe('overdue');
});

it('leaves invoices with a future due date untouched', function (): void {
    $invoice = createInvoiceWithStatus('issued', '2026-03-15', 500);

    $updated = Invoice::markOverdue();

    expect($updated)->toBe(0);
    expect($invoice->refresh()->status?->value)->toBe('issued');
});

it('leaves fully paid, cancelled and refunded invoices untouched', function (): void {
    $paid = createInvoiceWithStatus('paid', '2026-03-15', 0);
    $cancelled = createInvoiceWithStatus('cancelled', '2026-03-15', 500);
    $refund = createInvoiceWithStatus('refund', '2026-03-15', 500);

    Carbon::setTestNow(Carbon::parse('2026-03-20 10:00:00', 'UTC'));

    $updated = Invoice::markOverdue();

    expect($updated)->toBe(0);
    expect($paid->refresh()->status?->value)->toBe('paid');
    expect($cancelled->refresh()->status?->value)->toBe('cancelled');
    expect($refund->refresh()->status?->value)->toBe('refund');
});

it('does not mark an invoice overdue when nothing is due', function (): void {
    $invoice = createInvoiceWithStatus('issued', '2026-03-15', 0);

    Carbon::setTestNow(Carbon::parse('2026-03-20 10:00:00', 'UTC'));

    $updated = Invoice::markOverdue();

    expect($updated)->toBe(0);
    expect($invoice->refresh()->status?->value)->toBe('issued');
});

it('syncs the stored status on the invoice list page mount', function (): void {
    $user = User::factory()->create();
    $user->assignRole('owner');
    $this->actingAs($user);

    $invoice = createInvoiceWithStatus('issued', '2026-03-15', 500);

    Carbon::setTestNow(Carbon::parse('2026-03-20 10:00:00', 'UTC'));

    Livewire::test(ListInvoices::class)
        ->assertSuccessful();

    expect($invoice->refresh()->status?->value)->toBe('overdue');
});
