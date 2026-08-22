<?php

declare(strict_types=1);

namespace App\Filament\Shield\Pages;

use App\Filament\Shield\RoleResource;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\ViewRole as ShieldViewRole;

class ViewRole extends ShieldViewRole
{
    protected static string $resource = RoleResource::class;
}
