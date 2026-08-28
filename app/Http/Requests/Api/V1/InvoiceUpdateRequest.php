<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\ResolvesRouteKey;
use App\Services\Api\Schemas\InvoiceSchema;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class InvoiceUpdateRequest extends FormRequest
{
    use ResolvesRouteKey;

    

    public function authorize(): bool
    {
        return true;
    }

    

    public function rules(): array
    {
        $invoiceId = $this->routeKey('invoice');

        return InvoiceSchema::updateRules($invoiceId);
    }
}
