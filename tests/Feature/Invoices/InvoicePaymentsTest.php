<?php

use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'owner']);
});

function paymentsStaff()
{
    $staff = \App\Models\User::factory()->create();
    $staff->assignRole('owner');

    return $staff;
}

function openInvoice(array $overrides = []): Invoice
{
    $subscription = Subscription::factory()->create();

    return Invoice::factory()->create(array_merge([
        'subscription_id' => $subscription->id,
        'status' => 'issued',
        'subscription_fee' => 100,
        'discount' => null,
        'discount_amount' => 0,
        'paid_amount' => 0,
        'due_date' => now()->addDays(10),
    ], $overrides));
}

it('records a payment from the invoice view page and updates the ledger totals', function (): void {
    $invoice = openInvoice();

    expect($invoice->due_amount)->toBeGreaterThan(0);

    Livewire\Livewire::actingAs(paymentsStaff())
        ->test(ViewInvoice::class, ['record' => $invoice->id])
        ->assertSee('whitespace-nowrap', false)
        ->callAction('add_payment', data: [
            'amount' => 40,
            'occurred_at' => now()->format('Y-m-d H:i:s'),
            'payment_method' => 'cash',
            'note' => 'First installment',
        ])
        ->assertHasNoActionErrors();

    $invoice->refresh();

    expect($invoice->transactions()->where('type', 'payment')->count())->toBe(1)
        ->and($invoice->transactions()->where('type', 'payment')->first()->amount)->toBe(40.0)
        ->and((float) $invoice->paid_amount)->toBe(40.0)
        ->and((float) $invoice->due_amount)->toBe((float) $invoice->total_amount - 40.0)
        ->and($invoice->status->value)->toBe('partial');
});

it('marks the invoice paid once the due amount is fully settled', function (): void {
    $invoice = openInvoice();

    Livewire\Livewire::actingAs(paymentsStaff())
        ->test(ViewInvoice::class, ['record' => $invoice->id])
        ->callAction('add_payment', data: [
            'amount' => (float) $invoice->due_amount,
            'occurred_at' => now()->format('Y-m-d H:i:s'),
            'payment_method' => 'cash',
        ]);

    $invoice->refresh();

    expect((float) $invoice->due_amount)->toBe(0.0)
        ->and($invoice->status->value)->toBe('paid');
});

it('hides the record payment action when nothing is owed', function (): void {
    $invoice = openInvoice(['status' => 'paid']);
    $invoice->transactions()->create([
        'type' => 'payment',
        'amount' => $invoice->total_amount,
        'occurred_at' => now(),
        'created_by' => null,
    ]);
    $invoice->refresh();

    Livewire\Livewire::actingAs(paymentsStaff())
        ->test(ViewInvoice::class, ['record' => $invoice->id])
        ->assertActionHidden('add_payment');
});

it('persists a custom discount amount without a percentage on edit', function (): void {
    $invoice = openInvoice(['discount_amount' => 10, 'payment_method' => 'cash']);

    Livewire\Livewire::actingAs(paymentsStaff())
        ->test(EditInvoice::class, ['record' => $invoice->id])
        ->fillForm(['discount_amount' => 20])
        ->call('save')
        ->assertHasNoFormErrors();

    $invoice->refresh();

    expect((float) $invoice->discount_amount)->toBe(20.0)
        ->and($invoice->discount)->toBeNull();
});
