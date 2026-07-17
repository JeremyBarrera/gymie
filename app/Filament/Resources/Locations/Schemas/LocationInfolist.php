<?php

namespace App\Filament\Resources\Locations\Schemas;

use App\Models\Location;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

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
                                TextEntry::make('managedBy.name')->label(__('app.fields.managed_by')),
                                TextEntry::make('phone')->label(__('app.fields.phone')),
                            ])
                            ->columns(3),
                        TextEntry::make('address')
                            ->label(__('app.fields.address'))
                            ->columnSpanFull(),
                        Group::make()
                            ->schema([
                                TextEntry::make('country')->label(__('app.fields.country')),
                                TextEntry::make('state')->label(__('app.fields.state')),
                                TextEntry::make('city')->label(__('app.fields.city')),
                                TextEntry::make('pincode')->label(__('app.fields.pincode')),
                            ])
                            ->columns(4),
                    ]),
            ]);
    }
}
