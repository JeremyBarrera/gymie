<?php

namespace App\Jobs;

use App\Services\Email\InvoiceEmailService;
use App\Support\Invoices\InvoiceDocumentNotRenderable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendInvoicePaymentReceiptEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    

    public int $tries = 3;

    public int $timeout = 60;

    
    public array $backoff = [10, 60, 300];

    

    public function __construct(
        public readonly int $invoiceId,
        public readonly int $invoiceTransactionId,
        public readonly string $toEmail,
        public readonly ?string $note = null,
        public readonly ?int $actorId = null,
    ) {}

    

    public function handle(InvoiceEmailService $service): void
    {
        try {
            $service->sendPaymentReceiptEmail(
                invoiceId: $this->invoiceId,
                transactionId: $this->invoiceTransactionId,
                toEmail: $this->toEmail,
                note: $this->note,
            );
        } catch (InvoiceDocumentNotRenderable $exception) {
            Log::warning('Skipping payment receipt email: missing required invoice data.', [
                'invoice_id' => $this->invoiceId,
                'invoice_transaction_id' => $this->invoiceTransactionId,
                'missing' => $exception->viewData['missing'],
            ]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Invoice payment receipt email job failed permanently.', [
            'invoice_id' => $this->invoiceId,
            'invoice_transaction_id' => $this->invoiceTransactionId,
            'actor_id' => $this->actorId,
            'exception' => $exception,
        ]);
    }
}
