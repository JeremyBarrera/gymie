<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\ResolvesRouteKey;
use App\Services\Api\Schemas\InvoiceSchema;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Update invoice request.
 */
class InvoiceUpdateRequest extends FormRequest
{
    use ResolvesRouteKey;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $invoiceId = $this->routeKey('invoice');

        return InvoiceSchema::updateRules($invoiceId);
    }
}
