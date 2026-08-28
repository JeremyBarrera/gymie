<?php

namespace App\Observers;

use App\Contracts\SettingsRepository;
use App\Jobs\SendInvoicePaymentReceiptEmail;
use App\Models\InvoiceTransaction;
use App\Support\Data;
use Illuminate\Support\Facades\Log;

class InvoiceTransactionObserver
{
    public function __construct(private readonly SettingsRepository $settingsRepository) {}

    

    public function created(InvoiceTransaction $invoiceTransaction): void
    {
        if ($invoiceTransaction->type !== 'payment') {
            return;
        }

        if (($invoiceTransaction->note ?? null) === 'Initial payment') {
            return;
        }

        $settings = $this->settingsRepository->get();

        if (
            ! (bool) data_get($settings, 'notifications.email.enabled', false) ||
            ! (bool) data_get($settings, 'notifications.email.auto_send_payment_receipt', false)
        ) {
            return;
        }

        $invoiceTransaction->loadMissing('invoice.subscription.member');

        $invoiceId = $invoiceTransaction->invoice?->getKey();
        if (! $invoiceId) {
            return;
        }

        $invoice = $invoiceTransaction->invoice;

        $email = $invoice->subscription && $invoice->subscription->member
            ? (string) $invoice->subscription->member->email
            : '';

        if (! filled($email)) {
            Log::info('Skipping payment receipt email: member email missing.', [
                'invoice_id' => $invoiceId,
                'invoice_transaction_id' => $invoiceTransaction->getKey(),
            ]);

            return;
        }

        SendInvoicePaymentReceiptEmail::dispatch(
            invoiceId: Data::int($invoiceId),
            invoiceTransactionId: Data::int($invoiceTransaction->getKey()),
            toEmail: $email,
        )->afterCommit();
    }

    
}
