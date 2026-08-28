<?php

namespace App\Observers;

use App\Contracts\SettingsRepository;
use App\Jobs\SendInvoiceIssuedEmail;
use App\Models\Invoice;
use App\Support\Data;
use Illuminate\Support\Facades\Log;

class InvoiceObserver
{
    public function __construct(private readonly SettingsRepository $settingsRepository) {}

    

    public function created(Invoice $invoice): void
    {
        $settings = $this->settingsRepository->get();

        if (
            ! (bool) data_get($settings, 'notifications.email.enabled', false) ||
            ! (bool) data_get($settings, 'notifications.email.auto_send_invoice_issued', false)
        ) {
            return;
        }

        $invoice->loadMissing('subscription.member');

        $email = $invoice->subscription && $invoice->subscription->member
            ? (string) $invoice->subscription->member->email
            : '';

        if (! filled($email)) {
            Log::info('Skipping invoice issued email: member email missing.', [
                'invoice_id' => $invoice->getKey(),
                'subscription_id' => $invoice->subscription_id,
            ]);

            return;
        }

        SendInvoiceIssuedEmail::dispatch(
            invoiceId: Data::int($invoice->getKey()),
            toEmail: $email,
        )->afterCommit();
    }

    
}
