<?php

namespace App\Filament\Resources\Locations;

use App\Filament\Resources\Locations\Pages\CreateLocation;
use App\Filament\Resources\Locations\Pages\EditLocation;
use App\Filament\Resources\Locations\Pages\ListLocations;
use App\Filament\Resources\Locations\Pages\ViewLocation;
use App\Filament\Resources\Locations\Schemas\LocationForm;
use App\Filament\Resources\Locations\Schemas\LocationInfolist;
use App\Filament\Resources\Locations\Tables\LocationTable;
use App\Models\Location;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LocationResource extends Resource
{
    protected static ?string $model = Location::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('app.resources.locations.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('app.resources.locations.plural');
    }

    public static function getNavigationLabel(): string
    {
        return static::getPluralModelLabel();
    }

    public static function getGloballySearchableAttributes(): array
    {
        return [
            'name',
            'address',
            'city',
            'state',
        ];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Location $record */
        $details = [];

        if (filled($record->address)) {
            $details[__('app.fields.address')] = $record->address;
        }

        if (filled($record->city)) {
            $details[__('app.fields.city')] = $record->city;
        }

        if (filled($record->state)) {
            $details[__('app.fields.state')] = $record->state;
        }

        if (filled($record->country)) {
            $details[__('app.fields.country')] = $record->country;
        }

        if (filled($record->phone)) {
            $details[__('app.fields.phone')] = $record->phone;
        }

        return $details;
    }

    public static function form(Schema $schema): Schema
    {
        return LocationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LocationTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return LocationInfolist::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLocations::route('/'),
            'create' => CreateLocation::route('/create'),
            'edit' => EditLocation::route('/{record}/edit'),
            'view' => ViewLocation::route('/{record}'),
        ];
    }

    /**
     * @return Builder<Location>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('members');
    }

    /**
     * @param  Builder<Location>  $query
     */
    public static function modifyGlobalSearchQuery(Builder $query, string $search): void
    {
        // No extra eager loads required for searchable columns on the model.
    }
}
