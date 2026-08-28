<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\V1\PermissionResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\Permission\Models\Permission;

class PermissionsController extends ApiController
{
    

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->requirePermission($request, 'ViewAny:Role');

        $permissions = Permission::query()
            ->orderBy('name')
            ->get();

        return PermissionResource::collection($permissions);
    }
}
