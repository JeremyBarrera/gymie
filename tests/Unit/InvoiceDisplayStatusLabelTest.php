<?php

use App\Models\Invoice;

it('shows issued label for issued invoices', function (): void {
    $invoice = new Invoice([
        'status' => 'issued',
    ]);

    expect($invoice->getDisplayStatusLabel())->toBe(__('app.status.issued'));
});

it('shows paid label for paid invoices', function (): void {
    $invoice = new Invoice([
        'status' => 'paid',
    ]);

    expect($invoice->getDisplayStatusLabel())->toBe(__('app.status.paid'));
});

it('shows overdue for an issued invoice whose due date has passed', function (): void {
    $invoice = new Invoice([
        'status' => 'issued',
        'due_amount' => 100,
        'due_date' => now()->subDay()->toDateString(),
    ]);

    expect($invoice->effectiveStatus()?->value)->toBe('overdue');
    expect($invoice->getDisplayStatusLabel())->toBe(__('app.status.overdue'));
});

it('shows overdue for a partial invoice whose due date has passed', function (): void {
    $invoice = new Invoice([
        'status' => 'partial',
        'due_amount' => 50,
        'due_date' => now()->subDay()->toDateString(),
    ]);

    expect($invoice->effectiveStatus()?->value)->toBe('overdue');
});

it('keeps issued for an issued invoice whose due date is in the future', function (): void {
    $invoice = new Invoice([
        'status' => 'issued',
        'due_amount' => 100,
        'due_date' => now()->addDay()->toDateString(),
    ]);

    expect($invoice->effectiveStatus()?->value)->toBe('issued');
});

it('does not mark fully paid invoices overdue', function (): void {
    $invoice = new Invoice([
        'status' => 'paid',
        'due_amount' => 0,
        'due_date' => now()->subDay()->toDateString(),
    ]);

    expect($invoice->effectiveStatus()?->value)->toBe('paid');
});

it('does not mark cancelled or refunded invoices overdue', function (): void {
    foreach (['cancelled', 'refund'] as $status) {
        $invoice = new Invoice([
            'status' => $status,
            'due_amount' => 100,
            'due_date' => now()->subDay()->toDateString(),
        ]);

        expect($invoice->effectiveStatus()?->value)->toBe($status);
    }
});
