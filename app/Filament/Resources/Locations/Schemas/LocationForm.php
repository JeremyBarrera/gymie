<?php

namespace App\Filament\Resources\Locations\Schemas;

use App\Helpers\Helpers;
use App\Models\User;
use App\Support\Billing\Currency;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

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
                        Helpers::phoneField('phone'),
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
                                    ->hidden(fn ($get) => empty(Helpers::getStates($get('country'))))
                                    ->afterStateUpdated(fn ($state, callable $set) => $set('city', null)),
                                Select::make('city')
                                    ->label(__('app.fields.city'))
                                    ->placeholder(__('app.placeholders.select_city'))
                                    ->options(fn ($get) => Helpers::getCities($get('state'), $get('country')))
                                    ->searchable()
                                    ->reactive()
                                    ->hidden(fn ($get) => empty(Helpers::getCities($get('state'), $get('country')))),
                                TextInput::make('pincode')
                                    ->label(__('app.fields.pincode'))
                                    ->maxLength(20),
                            ])->columns(4),
                        Select::make('currency')
                            ->label(__('app.fields.currency'))
                            ->placeholder(__('app.placeholders.use_global_currency'))
                            ->helperText(fn (): string => __('app.placeholders.currency_override_hint', ['code' => Currency::codeFromSettings(Helpers::getSettings())]))
                            ->options(Helpers::getCurrencies())
                            ->searchable()
                            ->preload()
                            ->rules(['nullable', 'string', 'size:3', Rule::in(Currency::codes())])
                            ->dehydrateStateUsing(fn (mixed $state): ?string => filled($state) ? strtoupper(trim((string) $state)) : null),
                        Select::make('managed_by')
                            ->label(__('app.fields.managed_by'))
                            ->options(fn (): array => User::orderBy('name')->pluck('name', 'id')->toArray())
                            ->searchable()
                            ->placeholder(__('app.placeholders.select_user'))
                            ->nullable(),
                        Grid::make()
                            ->schema([
                                ColorPicker::make('background_color')
                                    ->label(__('app.fields.background_color')),
                                ColorPicker::make('accent_color')
                                    ->label(__('app.fields.accent_color')),
                            ])->columns(2),
                    ]),
            ]);
    }
}
