<?php

namespace App\Filament\Resources\Services\Schemas;

use App\Support\Locations\LocationAccess;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class ServiceForm
{
    /**
     * Configure the service form schema.
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Select::make('location_id')
                    ->label(__('app.fields.location'))
                    ->options(fn (): array => LocationAccess::locationOptions(Auth::user()))
                    ->default(fn (): ?int => LocationAccess::firstAccessibleLocationId(Auth::user()))
                    ->searchable()
                    ->preload()
                    ->required()
                    ->helperText(__('app.helpers.service_location')),
                TextInput::make('name')
                    ->label(__('app.fields.name'))
                    ->placeholder(__('app.placeholders.service_name'))
                    ->required(),
                Textarea::make('description')
                    ->placeholder(__('app.placeholders.service_description'))
                    ->label(__('app.fields.description')),
            ]);
    }
}
