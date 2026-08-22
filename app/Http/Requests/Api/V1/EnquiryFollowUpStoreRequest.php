<?php

namespace App\Http\Requests\Api\V1;

use App\Services\Api\Schemas\FollowUpSchema;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create follow-up under an enquiry.
 */
class EnquiryFollowUpStoreRequest extends FormRequest
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
        return FollowUpSchema::nestedStoreRules();
    }
}
