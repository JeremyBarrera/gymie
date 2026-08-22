<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\ResolvesRouteKey;
use App\Services\Api\Schemas\PlanSchema;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Update plan request.
 */
class PlanUpdateRequest extends FormRequest
{
    use ResolvesRouteKey;

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
        $planId = $this->routeKey('plan');

        return PlanSchema::updateRules($planId);
    }
}
