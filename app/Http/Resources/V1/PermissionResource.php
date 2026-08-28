<?php

namespace App\Http\Resources\V1;

use App\Services\Api\Schemas\PermissionSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Models\Permission;

class PermissionResource extends JsonResource
{
    

    public function toArray(Request $request): array
    {
        
        $permission = $this->resource;

        return PermissionSchema::resource($permission);
    }
}
