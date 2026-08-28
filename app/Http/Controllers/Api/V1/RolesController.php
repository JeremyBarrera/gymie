<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\V1\RoleResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\Permission\Models\Role;

class RolesController extends ApiController
{
    

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->requirePermission($request, 'ViewAny:Role');

        $roles = Role::query()
            ->with('permissions')
            ->orderBy('name')
            ->get();

        return RoleResource::collection($roles);
    }
}
