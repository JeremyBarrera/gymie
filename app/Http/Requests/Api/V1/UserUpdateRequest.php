<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\ResolvesRouteKey;
use App\Services\Api\Schemas\UserSchema;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UserUpdateRequest extends FormRequest
{
    use ResolvesRouteKey;

    

    public function authorize(): bool
    {
        return true;
    }

    

    public function rules(): array
    {
        $userId = $this->routeKey('user');

        return UserSchema::updateRules($userId);
    }
}
