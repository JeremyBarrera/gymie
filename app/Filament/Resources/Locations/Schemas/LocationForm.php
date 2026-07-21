<?php

namespace App\Filament\Resources\Locations\Schemas;

use App\Helpers\Helpers;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LocationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->schema([
                        TextInput::make('name')
                            ->label(__('app.fields.name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->label(__('app.fields.phone'))
                            ->tel()
                            ->maxLength(20),
                        Textarea::make('address')
                            ->label(__('app.fields.address'))
                            ->rows(4)
                            ->maxLength(1000),
                        Grid::make()
                            ->schema([
                                Select::make('country')
                                    ->label(__('app.fields.country'))
                                    ->placeholder(__('app.placeholders.select_country'))
                                    ->options(Helpers::getCountries())
                                    ->searchable()
                                    ->preload()
                                    ->reactive()
                                    ->afterStateUpdated(fn ($state, callable $set) => [
                                        $set('state', null),
                                        $set('city', null),
                                    ]),
                                Select::make('state')
                                    ->label(__('app.fields.state'))
                                    ->placeholder(__('app.placeholders.select_state'))
                                    ->options(fn ($get) => Helpers::getStates($get('country')))
                                    ->searchable()
                                    ->reactive()
                                    ->afterStateUpdated(fn ($state, callable $set) => $set('city', null)),
                                Select::make('city')
                                    ->label(__('app.fields.city'))
                                    ->placeholder(__('app.placeholders.select_city'))
                                    ->options(fn ($get) => Helpers::getCities($get('state')))
                                    ->searchable()
                                    ->reactive(),
                                TextInput::make('pincode')
                                    ->label(__('app.fields.pincode'))
                                    ->maxLength(20),
                            ])->columns(4),
                        Select::make('managed_by')
                            ->label(__('app.fields.managed_by'))
                            ->options(fn (): array => User::role('super_admin')->orderBy('name')->pluck('name', 'id')->toArray())
                            ->searchable()
                            ->placeholder(__('app.placeholders.select_super_admin'))
                            ->nullable(),
                    ]),
            ]);
    }
}
