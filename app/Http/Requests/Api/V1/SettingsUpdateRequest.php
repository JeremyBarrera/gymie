<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SettingsUpdateRequest extends FormRequest
{
    

    public function authorize(): bool
    {
        return true;
    }

    

    public function rules(): array
    {
        return [
            'general' => ['sometimes', 'array'],
            'invoice' => ['sometimes', 'array'],
            'member' => ['sometimes', 'array'],
            'charges' => ['sometimes', 'array'],
            'expenses' => ['sometimes', 'array'],
            'subscriptions' => ['sometimes', 'array'],
            'payments' => ['sometimes', 'array'],
            'notifications' => ['sometimes', 'array'],
            'notifications.email' => ['sometimes', 'array'],
        ];
    }
}
