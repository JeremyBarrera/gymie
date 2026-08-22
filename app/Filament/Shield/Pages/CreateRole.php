<?php

declare(strict_types=1);

namespace App\Filament\Shield\Pages;

use App\Filament\Shield\RoleResource;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\CreateRole as ShieldCreateRole;

class CreateRole extends ShieldCreateRole
{
    protected static string $resource = RoleResource::class;
}
