<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\ResolvesRouteKey;
use App\Services\Api\Schemas\UserSchema;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Update user request.
 */
class UserUpdateRequest extends FormRequest
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
        $userId = $this->routeKey('user');

        return UserSchema::updateRules($userId);
    }
}
