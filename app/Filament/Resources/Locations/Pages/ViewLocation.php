<?php

namespace App\Filament\Resources\Locations\Pages;

use App\Filament\Resources\Locations\LocationResource;
use App\Models\Location;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * @property-read Location $record
 */
class ViewLocation extends ViewRecord
{
    protected static string $resource = LocationResource::class;

    public function getTitle(): string
    {
        return $this->record->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            DeleteAction::make(),
        ];
    }

    public function getBreadcrumbs(): array
    {
        return [
            __('app.navigation.groups.administration'),
            LocationResource::getUrl('index') => LocationResource::getNavigationLabel(),
            $this->record->name,
        ];
    }
}
