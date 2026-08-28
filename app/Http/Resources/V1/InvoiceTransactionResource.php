<?php

namespace App\Http\Resources\V1;

use App\Models\InvoiceTransaction;
use App\Services\Api\Schemas\InvoiceTransactionSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceTransactionResource extends JsonResource
{
    

    public function toArray(Request $request): array
    {
        
        $transaction = $this->resource;

        return InvoiceTransactionSchema::resource($transaction);
    }
}
