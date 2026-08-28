<?php

namespace App\Support\Invoices;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;

final class InvoicePdfRenderer
{
    

    public function render(Invoice $invoice): string
    {
        $this->ensureDompdfFontCacheDirectoryExists();

        $data = InvoiceDocument::viewData($invoice);

        if ($data['missing'] !== []) {
            throw new InvoiceDocumentNotRenderable($data);
        }

        return Pdf::loadView('invoices.document', $data)
            ->setPaper('a4')
            ->output();
    }

    

    private function ensureDompdfFontCacheDirectoryExists(): void
    {
        File::ensureDirectoryExists(storage_path('fonts'));
    }
}
