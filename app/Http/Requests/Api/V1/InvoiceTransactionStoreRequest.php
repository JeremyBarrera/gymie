<?php

namespace App\Http\Requests\Api\V1;

use App\Services\Api\Schemas\InvoiceTransactionSchema;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class InvoiceTransactionStoreRequest extends FormRequest
{
    

    public function authorize(): bool
    {
        return true;
    }

    

    public function rules(): array
    {
        return InvoiceTransactionSchema::storeRules();
    }
}
