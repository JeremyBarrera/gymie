<?php

namespace App\Filament\Resources\Services\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ServiceInfolist
{
    

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('')
                    ->schema([
                        TextEntry::make('location.name')
                            ->label(__('app.fields.location'))
                            ->placeholder(__('app.fields.unassigned')),
                        TextEntry::make('name')
                            ->label(__('app.fields.name')),
                        TextEntry::make('description')
                            ->label(__('app.fields.description')),
                    ])->columns(1),
            ]);
    }
}
