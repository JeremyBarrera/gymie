<?php

namespace App\Http\Requests\Api\V1;

use App\Services\Api\Schemas\SubscriptionSchema;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Update subscription request.
 */
class SubscriptionUpdateRequest extends FormRequest
{
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
        return SubscriptionSchema::updateRules();
    }
}
