<?php

namespace App\Services\Api\Schemas;

use Spatie\Permission\Models\Permission;

final class PermissionSchema
{
    private function __construct() {}

    

    public static function resource(Permission $permission): array
    {
        return [
            'id' => (int) $permission->id,
            'name' => (string) $permission->name,
            'guard_name' => (string) $permission->guard_name,
        ];
    }
}
