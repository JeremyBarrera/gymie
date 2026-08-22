<?php

namespace App\Filament\Resources\Locations\Schemas;

use App\Models\Location;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LocationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->schema([
                        Group::make()
                            ->schema([
                                TextEntry::make('name')->label(__('app.fields.name')),
                                TextEntry::make('managedBy.name')
                                    ->label(__('app.fields.managed_by'))
                                    ->placeholder(__('app.placeholders.dash')),
                                TextEntry::make('phone')
                                    ->label(__('app.fields.phone'))
                                    ->placeholder(__('app.placeholders.dash')),
                                TextEntry::make('members_count')
                                    ->label(__('app.resources.members.plural'))
                                    ->default(0),
                            ])
                            ->columns(4),
                        TextEntry::make('address')
                            ->label(__('app.fields.address'))
                            ->placeholder(__('app.placeholders.dash'))
                            ->columnSpanFull(),
                        TextEntry::make('formatted_place')
                            ->label(__('app.ui.location'))
                            ->state(fn (Location $record): ?string => collect([
                                $record->city,
                                $record->state,
                                $record->country,
                            ])->filter()->implode(', ') ?: null)
                            ->placeholder(__('app.placeholders.dash'))
                            ->columnSpanFull(),
                        Group::make()
                            ->schema([
                                TextEntry::make('country')
                                    ->label(__('app.fields.country'))
                                    ->placeholder(__('app.placeholders.dash')),
                                TextEntry::make('state')
                                    ->label(__('app.fields.state'))
                                    ->placeholder(__('app.placeholders.dash')),
                                TextEntry::make('city')
                                    ->label(__('app.fields.city'))
                                    ->placeholder(__('app.placeholders.dash')),
                                TextEntry::make('pincode')
                                    ->label(__('app.fields.pincode'))
                                    ->placeholder(__('app.placeholders.dash')),
                            ])
                            ->columns(4),
                        Group::make()
                            ->schema([
                                TextEntry::make('background_color')
                                    ->label(__('app.fields.background_color'))
                                    ->placeholder(__('app.placeholders.dash'))
                                    ->color(fn ($state): ?string => $state),
                                TextEntry::make('accent_color')
                                    ->label(__('app.fields.accent_color'))
                                    ->placeholder(__('app.placeholders.dash'))
                                    ->color(fn ($state): ?string => $state),
                            ])
                            ->columns(2),
                    ]),
            ]);
    }
}
