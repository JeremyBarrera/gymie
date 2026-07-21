<?php

namespace App\Filament\Resources\Locations\Tables;

use App\Helpers\Helpers;
use App\Models\Location;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LocationTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('name')
                    ->label(__('app.fields.name'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (Location $record): ?string => collect([
                        $record->city,
                        $record->state,
                        $record->country,
                    ])->filter()->implode(', ') ?: null),
                TextColumn::make('address')
                    ->label(__('app.fields.address'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->limit(40),
                TextColumn::make('city')
                    ->label(__('app.fields.city'))
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('state')
                    ->label(__('app.fields.state'))
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('country')
                    ->label(__('app.fields.country'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('members_count')
                    ->label(__('app.resources.members.plural'))
                    ->sortable()
                    ->alignCenter(),
                TextColumn::make('managedBy.name')
                    ->label(__('app.fields.managed_by'))
                    ->searchable()
                    ->placeholder(__('app.placeholders.dash')),
                TextColumn::make('phone')
                    ->label(__('app.fields.phone'))
                    ->searchable()
                    ->placeholder(__('app.placeholders.dash')),
            ])
            ->filters([
                Filter::make('country')
                    ->schema([
                        Select::make('country')
                            ->label(__('app.fields.country'))
                            ->placeholder(__('app.placeholders.select_country'))
                            ->options(Helpers::getCountries())
                            ->searchable()
                            ->preload(),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['country'] ?? null,
                            fn (Builder $query, string $country): Builder => $query->where('country', $country),
                        );
                    }),
            ])
            ->emptyStateIcon('heroicon-o-map-pin')
            ->emptyStateHeading(__('app.empty.no_records', [
                'records' => __('app.resources.locations.plural'),
            ]))
            ->emptyStateDescription(__('app.empty.create_to_get_started', [
                'resource' => __('app.resources.locations.singular'),
            ]))
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }
}
