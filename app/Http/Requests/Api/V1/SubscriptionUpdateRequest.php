<?php

namespace App\Http\Requests\Api\V1;

use App\Services\Api\Schemas\SubscriptionSchema;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SubscriptionUpdateRequest extends FormRequest
{
    

    public function authorize(): bool
    {
        return true;
    }

    

    public function rules(): array
    {
        return SubscriptionSchema::updateRules();
    }
}
