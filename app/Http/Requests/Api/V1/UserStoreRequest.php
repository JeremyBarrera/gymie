<?php

namespace App\Http\Requests\Api\V1;

use App\Services\Api\Schemas\UserSchema;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UserStoreRequest extends FormRequest
{
    

    public function authorize(): bool
    {
        return true;
    }

    

    public function rules(): array
    {
        return UserSchema::storeRules();
    }
}
