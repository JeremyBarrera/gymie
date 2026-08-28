<?php

namespace App\Http\Requests\Api\V1;

use App\Services\Api\Schemas\ExpenseSchema;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ExpenseStoreRequest extends FormRequest
{
    

    public function authorize(): bool
    {
        return true;
    }

    

    public function rules(): array
    {
        return ExpenseSchema::storeRules();
    }
}
