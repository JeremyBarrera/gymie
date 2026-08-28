<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\InvoiceStoreRequest;
use App\Http\Requests\Api\V1\InvoiceUpdateRequest;
use App\Http\Resources\V1\InvoiceResource;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Services\Api\QueryFilters;
use App\Support\Data;
use App\Support\Invoices\InvoiceDocument;
use App\Support\Invoices\InvoiceDocumentNotRenderable;
use App\Support\Invoices\InvoicePdfRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class InvoicesController extends ApiController
{
    private const RESOURCE_KEY = 'invoices';

    

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->requirePermission($request, 'ViewAny:Invoice');

        $query = Invoice::query()
            ->with(['subscription.member', 'subscription.plan']);

        QueryFilters::applyIndexFilters($query, $request, self::RESOURCE_KEY);

        $perPage = QueryFilters::perPage($request->query('per_page'));

        return InvoiceResource::collection($query->paginate($perPage));
    }

    

    public function store(InvoiceStoreRequest $request): InvoiceResource
    {
        $this->requirePermission($request, 'Create:Invoice');

        $data = $request->validated();

        
        $subscription = Subscription::query()
            ->with('plan')
            ->findOrFail(Data::int($data['subscription_id'] ?? null));

        $data['due_date'] = $data['due_date'] ?? $data['date'];
        $data['status'] = $data['status'] ?? 'issued';

        if (! array_key_exists('subscription_fee', $data) || $data['subscription_fee'] === null) {
            $data['subscription_fee'] = $subscription->plan
                ? (float) $subscription->plan->amount
                : 0.0;
        }

        $invoice = Invoice::create($data);
        $invoice->load(['subscription.member', 'subscription.plan']);

        return new InvoiceResource($invoice);
    }

    

    public function show(Request $request, Invoice $invoice): InvoiceResource
    {
        $this->requirePermission($request, 'View:Invoice');

        $invoice->load(['subscription.member', 'subscription.plan']);

        return new InvoiceResource($invoice);
    }

    

    public function update(InvoiceUpdateRequest $request, Invoice $invoice): InvoiceResource
    {
        $this->requirePermission($request, 'Update:Invoice');

        $invoice->update($request->validated());
        $invoice->load(['subscription.member', 'subscription.plan']);

        return new InvoiceResource($invoice);
    }

    

    public function destroy(Request $request, Invoice $invoice): JsonResponse
    {
        return $this->deleteModel($request, 'Delete:Invoice', $invoice);
    }

    

    public function restore(Request $request, int $invoice): InvoiceResource
    {
        $record = $this->restoreSoftDeleted($request, 'RestoreAny:Invoice', Invoice::class, $invoice);
        $record->load(['subscription.member', 'subscription.plan']);

        return new InvoiceResource($record->refresh());
    }

    

    public function forceDelete(Request $request, int $invoice): JsonResponse
    {
        $this->forceDeleteSoftDeleted($request, 'ForceDeleteAny:Invoice', Invoice::class, $invoice);

        return $this->noContent();
    }

    

    public function pdf(Request $request, Invoice $invoice, InvoicePdfRenderer $renderer): Response|JsonResponse
    {
        $this->requirePermission($request, 'View:Invoice');

        $invoice = InvoiceDocument::loadForRendering($invoice);

        try {
            $pdfBytes = $renderer->render($invoice);
        } catch (InvoiceDocumentNotRenderable $exception) {
            return response()->json([
                'message' => 'Invoice can’t be generated.',
                'missing' => $exception->viewData['missing'],
            ], 422);
        }

        return response($pdfBytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.InvoiceDocument::pdfFilename($invoice).'"',
        ]);
    }

    

    public function downloadPdf(Request $request, Invoice $invoice, InvoicePdfRenderer $renderer): Response|JsonResponse
    {
        $this->requirePermission($request, 'View:Invoice');

        $invoice = InvoiceDocument::loadForRendering($invoice);

        try {
            $pdfBytes = $renderer->render($invoice);
        } catch (InvoiceDocumentNotRenderable $exception) {
            return response()->json([
                'message' => 'Invoice can’t be generated.',
                'missing' => $exception->viewData['missing'],
            ], 422);
        }

        return response($pdfBytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.InvoiceDocument::pdfFilename($invoice).'"',
        ]);
    }
}
