<?php

namespace App\Filament\Resources\Plans\Schemas;

use App\Helpers\Helpers;
use App\Models\Plan;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

class PlanInfolist
{
    /**
     * Configure the plan "view" infolist schema.
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Fieldset::make('')
                    ->label(function (Plan $record): HtmlString {
                        $status = $record->status;

                        if ($status === null) {
                            return new HtmlString('');
                        }

                        $html = Blade::render(
                            '<x-filament::badge class="inline-flex ml-2" :color="$color">
                                {{ $label }}
                            </x-filament::badge>',
                            [
                                'color' => $status->getColor(),
                                'label' => $status->getLabel(),
                            ]
                        );

                        return new HtmlString($html);
                    })
                    ->schema([
                        TextEntry::make('code')
                            ->label(__('app.fields.code'))
                            ->columnSpan(1),
                        TextEntry::make('name')
                            ->label(__('app.fields.name'))
                            ->columnSpan(2),
                        TextEntry::make('location.name')
                            ->label(__('app.fields.location'))
                            ->formatStateUsing(fn ($state): string => (string) ($state ?? __('app.options.all_locations'))),
                        TextEntry::make('services.name')
                            ->label(__('app.fields.service'))
                            ->badge(),
                        TextEntry::make('days')
                            ->label(__('app.fields.days'))
                            ->placeholder(__('app.fields.unlimited')),
                        TextEntry::make('amount')
                            ->label(__('app.fields.amount'))
                            ->money(Helpers::getCurrencyCode()),
                        TextEntry::make('track_uses')
                            ->label(__('app.fields.track_uses'))
                            ->formatStateUsing(fn (Plan $record): string => $record->track_uses
                                ? __('app.common.yes')
                                : __('app.common.no')),
                        TextEntry::make('uses_limit')
                            ->label(__('app.fields.uses_limit'))
                            ->formatStateUsing(fn (Plan $record): string => $record->track_uses
                                ? (string) ($record->uses_limit ?? 0)
                                : __('app.fields.unlimited')),
                        TextEntry::make('description')
                            ->label(__('app.fields.description'))
                            ->columnSpanFull(),
                    ])->columns(3),
            ]);
    }
}
