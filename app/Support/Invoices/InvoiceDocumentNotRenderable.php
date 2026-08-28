<?php

namespace App\Support\Invoices;

use App\Models\Invoice;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Subscription;
use RuntimeException;

final class InvoiceDocumentNotRenderable extends RuntimeException
{
    

    public function __construct(public readonly array $viewData)
    {
        $missing = $viewData['missing'];
        $missingText = $missing ? implode(', ', $missing) : 'Missing required data';

        parent::__construct("Invoice document cannot be rendered: {$missingText}");
    }
}
