<?php

namespace App\Http\Resources\V1;

use App\Models\Invoice;
use App\Services\Api\Schemas\InvoiceSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    

    public function toArray(Request $request): array
    {
        
        $invoice = $this->resource;

        return InvoiceSchema::resource($invoice);
    }
}
