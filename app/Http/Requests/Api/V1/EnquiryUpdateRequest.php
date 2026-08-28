<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\ResolvesRouteKey;
use App\Services\Api\Schemas\EnquirySchema;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class EnquiryUpdateRequest extends FormRequest
{
    use ResolvesRouteKey;

    

    public function authorize(): bool
    {
        return true;
    }

    

    public function rules(): array
    {
        $enquiryId = $this->routeKey('enquiry');

        return EnquirySchema::updateRules($enquiryId);
    }
}
