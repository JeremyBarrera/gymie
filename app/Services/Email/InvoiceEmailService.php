<?php

namespace App\Services\Email;

use App\Contracts\SettingsRepository;
use App\Helpers\Helpers;
use App\Jobs\SendInvoiceIssuedEmail;
use App\Jobs\SendInvoicePaymentReceiptEmail;
use App\Mail\InvoiceIssuedMail;
use App\Mail\InvoicePaymentReceiptMail;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Location;
use App\Support\AppConfig;
use App\Support\Data;
use App\Support\Invoices\InvoiceDocument;
use App\Support\Invoices\InvoicePdfRenderer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class InvoiceEmailService
{
    public function __construct(
        private readonly InvoicePdfRenderer $renderer,
        private readonly SettingsRepository $settingsRepository,
    ) {}

    

    public function queueInvoiceIssuedEmail(int $invoiceId, string $toEmail, ?string $note = null, ?int $actorId = null): void
    {
        SendInvoiceIssuedEmail::dispatch(
            invoiceId: $invoiceId,
            toEmail: $toEmail,
            note: $note,
            actorId: $actorId,
        )->afterCommit();
    }

    

    public function queuePaymentReceiptEmail(int $invoiceId, int $transactionId, string $toEmail, ?string $note = null, ?int $actorId = null): void
    {
        SendInvoicePaymentReceiptEmail::dispatch(
            invoiceId: $invoiceId,
            invoiceTransactionId: $transactionId,
            toEmail: $toEmail,
            note: $note,
            actorId: $actorId,
        )->afterCommit();
    }

    

    public function sendInvoiceIssuedEmail(int $invoiceId, string $toEmail, ?string $note = null): void
    {
        $invoice = InvoiceDocument::loadForRendering(Invoice::query()->findOrFail($invoiceId));
        $memberName = $invoice->subscription && $invoice->subscription->member
            ? (string) $invoice->subscription->member->name
            : '';

        if (! $this->isValidRecipientEmail($toEmail, [
            'invoice_id' => $invoiceId,
        ])) {
            return;
        }

        $settings = $this->settingsRepository->get();

        $this->withLocaleFromSettings($settings, function () use ($settings, $invoice, $toEmail, $note, $memberName): void {
            $location = $this->locationIdentity($invoice, $settings);
            $gymName = $location['name'] !== '' ? $location['name'] : 'Gymie';
            $subjectTemplate = Data::string(data_get($settings,
                'notifications.email.invoice_subject_template',
                'Invoice {invoice_number} - {status}',
            ));

            $pdfBytes = $this->renderer->render($invoice);
            $subject = $this->renderSubjectTemplate(
                $subjectTemplate,
                $this->invoiceSubjectTokens($invoice, $gymName, $memberName),
            );

            $mailable = new InvoiceIssuedMail(
                invoice: $invoice,
                subjectLine: $subject,
                gymName: $gymName,
                gymEmail: $location['email'],
                gymContact: $location['contact'],
                memberName: $memberName,
                note: $note,
                pdfBytes: $pdfBytes,
            );

            if (filled($location['email'])) {
                $mailable->replyTo($location['email'], $gymName);
            }

            Mail::to($toEmail)->send($mailable);
        });
    }

    

    public function sendPaymentReceiptEmail(int $invoiceId, int $transactionId, string $toEmail, ?string $note = null): void
    {
        $invoice = InvoiceDocument::loadForRendering(Invoice::query()->findOrFail($invoiceId));
        $transaction = InvoiceTransaction::query()
            ->whereKey($transactionId)
            ->where('invoice_id', $invoice->getKey())
            ->firstOrFail();

        $memberName = $invoice->subscription && $invoice->subscription->member
            ? (string) $invoice->subscription->member->name
            : '';

        if (! $this->isValidRecipientEmail($toEmail, [
            'invoice_id' => $invoiceId,
            'invoice_transaction_id' => $transactionId,
        ])) {
            return;
        }

        $settings = $this->settingsRepository->get();

        $this->withLocaleFromSettings($settings, function () use ($settings, $invoice, $transaction, $toEmail, $note, $memberName): void {
            $location = $this->locationIdentity($invoice, $settings);
            $gymName = $location['name'] !== '' ? $location['name'] : 'Gymie';
            $subjectTemplate = Data::string(data_get($settings,
                'notifications.email.receipt_subject_template',
                'Payment received - {invoice_number}',
            ));

            $pdfBytes = $this->renderer->render($invoice);
            $subject = $this->renderSubjectTemplate(
                $subjectTemplate,
                [
                    ...$this->invoiceSubjectTokens($invoice, $gymName, $memberName),
                    'payment_amount' => Helpers::formatCurrency((float) ($transaction->amount ?? 0)),
                ],
            );

            $mailable = new InvoicePaymentReceiptMail(
                invoice: $invoice,
                transaction: $transaction,
                subjectLine: $subject,
                gymName: $gymName,
                gymEmail: $location['email'],
                gymContact: $location['contact'],
                memberName: $memberName,
                note: $note,
                pdfBytes: $pdfBytes,
            );

            if (filled($location['email'])) {
                $mailable->replyTo($location['email'], $gymName);
            }

            Mail::to($toEmail)->send($mailable);
        });
    }

    

    private function withLocaleFromSettings(array $settings, callable $callback): mixed
    {
        $originalLocale = app()->getLocale();
        $desiredLocale = Data::string(data_get($settings, 'general.locale', $originalLocale), $originalLocale);
        $supportedLocales = AppConfig::supportedLocales();

        if ($desiredLocale !== '' && in_array($desiredLocale, $supportedLocales, true)) {
            app()->setLocale($desiredLocale);
            Carbon::setLocale($desiredLocale);
        }

        try {
            return $callback();
        } finally {
            app()->setLocale($originalLocale);
            Carbon::setLocale($originalLocale);
        }
    }

    

    private function isValidRecipientEmail(string $toEmail, array $context): bool
    {
        if (filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return true;
        }

        Log::warning('Skipping invoice email: invalid recipient email.', [
            ...$context,
            'to_email' => $toEmail,
        ]);

        return false;
    }

    

    private function locationIdentity(Invoice $invoice, array $settings): array
    {
        $location = filled($invoice->location_id)
            ? Location::query()->find($invoice->location_id)
            : null;

        return [
            'name' => $location?->name ?: Data::string(data_get($settings, 'general.gym_name', AppConfig::string('app.name'))),
            'email' => $location?->email ?: Data::string(data_get($settings, 'general.gym_email', '')),
            'contact' => $location?->phone ?: Data::string(data_get($settings, 'general.gym_contact', '')),
        ];
    }

    

    private function invoiceSubjectTokens(Invoice $invoice, string $gymName, string $memberName): array
    {
        return [
            'invoice_number' => Data::string($invoice->number),
            'status' => $invoice->getDisplayStatusLabel(),
            'total' => Helpers::formatCurrency((float) ($invoice->total_amount ?? 0)),
            'paid' => Helpers::formatCurrency((float) ($invoice->paid_amount ?? 0)),
            'due' => Helpers::formatCurrency((float) ($invoice->due_amount ?? 0)),
            'gym_name' => $gymName,
            'member_name' => $memberName,
        ];
    }

    

    private function renderSubjectTemplate(string $template, array $tokens): string
    {
        $rendered = preg_replace_callback('/\{([a-z_]+)\}/i', function (array $matches) use ($tokens): string {
            $key = strtolower(Data::string($matches[1]));

            return array_key_exists($key, $tokens) ? $tokens[$key] : Data::string($matches[0]);
        }, $template) ?? $template;

        $rendered = trim($rendered);

        if ($rendered === '') {
            return __('app.resources.invoices.singular');
        }

        return Data::string(Str::of($rendered)->limit(150, '…'));
    }
}
