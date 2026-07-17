<?php

namespace App\Filament\Resources\Locations\Schemas;

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
                                    ->options(fn (): array => User::query()->pluck('country', 'country')->filter()->unique()->sort()->toArray())
                                    ->searchable()
                                    ->preload(),
                                TextInput::make('state')
                                    ->label(__('app.fields.state'))
                                    ->maxLength(255)
                                    ->placeholder(__('app.placeholders.select_state')),
                                TextInput::make('city')
                                    ->label(__('app.fields.city'))
                                    ->maxLength(255)
                                    ->placeholder(__('app.placeholders.select_city')),
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
