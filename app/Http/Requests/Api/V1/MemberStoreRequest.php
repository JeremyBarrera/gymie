<?php

namespace App\Http\Requests\Api\V1;

use App\Services\Api\Schemas\MemberSchema;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class MemberStoreRequest extends FormRequest
{
    

    public function authorize(): bool
    {
        return true;
    }

    

    public function rules(): array
    {
        return MemberSchema::storeRules();
    }
}
