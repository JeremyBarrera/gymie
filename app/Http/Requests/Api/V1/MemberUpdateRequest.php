<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\ResolvesRouteKey;
use App\Services\Api\Schemas\MemberSchema;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class MemberUpdateRequest extends FormRequest
{
    use ResolvesRouteKey;

    

    public function authorize(): bool
    {
        return true;
    }

    

    public function rules(): array
    {
        $memberId = $this->routeKey('member');

        return MemberSchema::updateRules($memberId);
    }
}
