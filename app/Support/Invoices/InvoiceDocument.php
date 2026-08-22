<?php

namespace App\Support\Invoices;

use App\Helpers\Helpers;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\AppConfig;
use Illuminate\Support\Carbon;

/**
 * Invoice document helper.
 *
 * This class is responsible for:
 * - Loading an invoice with the relationships needed for rendering.
 * - Validating that the invoice has the minimum data required for preview/download.
 * - Building a stable view payload for both HTML preview and PDF rendering.
 */
final class InvoiceDocument
{
    /**
     * Reload an invoice with the relationships needed to render an invoice document.
     *
     * Important: we include soft-deleted relations so invoices remain printable
     * even if a member/subscription was archived later.
     */
    public static function loadForRendering(Invoice $invoice): Invoice
    {
        /** @var Invoice $document */
        $document = Invoice::query()
            ->withTrashed()
            ->with([
                'subscription' => function ($query): void {
                    $query
                        ->withTrashed()
                        ->with([
                            'member' => fn ($q) => $q->withTrashed(),
                            'plan' => fn ($q) => $q->withTrashed(),
                        ]);
                },
                'transactions' => fn ($query) => $query->latest('occurred_at'),
            ])
            ->whereKey($invoice->getKey())
            ->firstOrFail();

        return $document;
    }

    /**
     * Get a list of missing required fields/relationships.
     *
     * @return list<string>
     */
    public static function missingRequiredData(Invoice $invoice): array
    {
        $missing = [];

        if (! filled($invoice->number)) {
            $missing[] = __('app.invoices.missing.invoice_number');
        }

        if (! filled($invoice->date)) {
            $missing[] = __('app.invoices.missing.invoice_date');
        }

        if (! $invoice->subscription) {
            $missing[] = __('app.invoices.missing.subscription');
        }

        if (! $invoice->subscription?->member) {
            $missing[] = __('app.invoices.missing.member');
        }

        if ((float) ($invoice->total_amount ?? 0) <= 0) {
            $missing[] = __('app.invoices.missing.total_amount');
        }

        return $missing;
    }

    /**
     * Determine if an invoice can be rendered as a document.
     */
    public static function canRender(Invoice $invoice): bool
    {
        $invoice = self::loadForRendering($invoice);

        return self::missingRequiredData($invoice) === [];
    }

    /**
     * Build the view payload used by both HTML preview and PDF download.
     *
     * @return array{
     *   invoice: Invoice,
     *   member: Member|null,
     *   subscription: Subscription|null,
     *   plan: Plan|null,
     *   settings: array<string, mixed>,
     *   missing: list<string>,
     *   generated_at: string,
     *   logo_data_uri: string|null,
     * }
     */
    public static function viewData(Invoice $invoice): array
    {
        $invoice = self::loadForRendering($invoice);
        $settings = Helpers::getSettings();
        $missing = self::missingRequiredData($invoice);

        return [
            'invoice' => $invoice,
            'member' => $invoice->subscription?->member,
            'subscription' => $invoice->subscription,
            'plan' => $invoice->subscription?->plan,
            'settings' => $settings,
            'missing' => $missing,
            'generated_at' => Carbon::now(AppConfig::timezone())->toDateTimeString(),
            'logo_data_uri' => self::logoDataUri($invoice),
        ];
    }

    /**
     * Create a safe, human-friendly PDF filename for an invoice.
     */
    public static function pdfFilename(Invoice $invoice): string
    {
        $safeNumber = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $invoice->number);
        $safeNumber = trim((string) $safeNumber, '-');

        return filled($safeNumber) ? "invoice-{$safeNumber}.pdf" : 'invoice.pdf';
    }

    /**
     * Resolve the location's logo into a data URI (best for PDF rendering),
     * falling back to the legacy settings logo.
     */
    private static function logoDataUri(Invoice $invoice): ?string
    {
        $raw = null;

        if (filled($invoice->location_id)) {
            $location = Location::query()->find($invoice->location_id);
            $raw = $location?->logo;
        }

        if (blank($raw)) {
            $raw = data_get(Helpers::getSettings(), 'general.gym_logo');
        }

        $path = null;
        if (is_string($raw) && filled($raw)) {
            $path = $raw;
        } elseif (is_array($raw) && isset($raw[0]) && is_string($raw[0]) && filled($raw[0])) {
            $path = $raw[0];
        }

        if (! $path) {
            return null;
        }

        $relative = ltrim($path, '/');

        $publicStoragePath = public_path('storage/'.$relative);
        if (file_exists($publicStoragePath)) {
            $mime = mime_content_type($publicStoragePath) ?: 'image/png';
            $data = base64_encode((string) file_get_contents($publicStoragePath));

            return "data:{$mime};base64,{$data}";
        }

        return null;
    }
}
