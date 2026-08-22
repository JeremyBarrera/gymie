<?php

declare(strict_types=1);

namespace App\Filament\Shield\Pages;

use App\Filament\Shield\RoleResource;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\EditRole as ShieldEditRole;

class EditRole extends ShieldEditRole
{
    protected static string $resource = RoleResource::class;
}
