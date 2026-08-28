<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\ResolvesRouteKey;
use App\Services\Api\Schemas\PlanSchema;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PlanUpdateRequest extends FormRequest
{
    use ResolvesRouteKey;

    

    public function authorize(): bool
    {
        return true;
    }

    

    public function rules(): array
    {
        $planId = $this->routeKey('plan');

        return PlanSchema::updateRules($planId);
    }
}
