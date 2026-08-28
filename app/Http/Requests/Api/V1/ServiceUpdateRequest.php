<?php

namespace App\Http\Requests\Api\V1;

use App\Services\Api\Schemas\ServiceSchema;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ServiceUpdateRequest extends FormRequest
{
    

    public function authorize(): bool
    {
        return true;
    }

    

    public function rules(): array
    {
        return ServiceSchema::updateRules();
    }
}
