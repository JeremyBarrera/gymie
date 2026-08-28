<?php

namespace App\Http\Resources\V1;

use App\Models\User;
use App\Services\Api\Schemas\UserSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    private function shouldIncludePermissions(Request $request): bool
    {
        if ($request->is('api/v1/me')) {
            return true;
        }

        $include = (string) $request->query('include', '');

        return in_array('permissions', array_filter(explode(',', $include)), true)
            || $request->boolean('include_permissions');
    }

    

    public function toArray(Request $request): array
    {
        
        $user = $this->resource;

        return UserSchema::resource($user, $this->shouldIncludePermissions($request));
    }
}
