<?php

namespace App\Http\Resources\V1;

use App\Services\Api\Schemas\RoleSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Models\Role;

class RoleResource extends JsonResource
{
    

    public function toArray(Request $request): array
    {
        
        $role = $this->resource;

        return RoleSchema::resource($role);
    }
}
