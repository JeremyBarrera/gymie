<?php

namespace App\Filament\Resources\Locations\Pages;

use App\Filament\Resources\Locations\LocationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLocation extends CreateRecord
{
    protected static string $resource = LocationResource::class;

    public function getBreadcrumbs(): array
    {
        return [
            __('app.navigation.groups.administration'),
            LocationResource::getUrl('index') => LocationResource::getNavigationLabel(),
        ];
    }
}
