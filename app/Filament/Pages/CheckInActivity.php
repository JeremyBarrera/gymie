<?php

namespace App\Filament\Pages;

use App\Models\Location;
use App\Models\PlanCheckIn;
use App\Support\Dates\DeviceDateFormat;
use App\Support\Locations\LocationAccess;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CheckInActivity extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?string $slug = 'activity';

    protected string $view = 'filament.pages.check-in-activity';

    public function getTitle(): string
    {
        return __('app.activity.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('app.navigation.activity');
    }

    public static function getNavigationSort(): int
    {
        return 0;
    }

    public function table(Table $table): Table
    {
        $accessible = LocationAccess::accessibleLocationIds(auth()->user());

        return $table
            ->query(
                PlanCheckIn::query()
                    ->with(['member', 'plan', 'location', 'checkedInBy'])
                    ->when($accessible !== null, fn (Builder $query): Builder => $query->whereIn('location_id', $accessible))
                    ->latest('checked_in_at')
            )
            ->columns([
                TextColumn::make('id')
                    ->label(__('app.fields.code'))
                    ->formatStateUsing(fn (int $state): string => "#{$state}")
                    ->sortable(),
                TextColumn::make('member.name')
                    ->label(__('app.fields.name'))
                    ->searchable(),
                TextColumn::make('plan.name')
                    ->label(__('app.resources.plans.singular'))
                    ->placeholder(__('app.placeholders.dash')),
                TextColumn::make('location.name')
                    ->label(__('app.fields.location'))
                    ->sortable(),
                TextColumn::make('override')
                    ->label(__('app.activity.override'))
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'warning' : 'gray')
                    ->formatStateUsing(fn (bool $state): string => $state ? __('app.activity.overridden') : __('app.activity.normal')),
                TextColumn::make('override_reason')
                    ->label(__('app.fields.note'))
                    ->placeholder(__('app.placeholders.dash'))
                    ->limit(40),
                TextColumn::make('checkedInBy.name')
                    ->label(__('app.fields.checked_in_by'))
                    ->placeholder(__('app.placeholders.dash')),
                TextColumn::make('checked_in_at')
                    ->label(__('app.activity.checked_in_at'))
                    ->dateTime(DeviceDateFormat::dateTime())
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('app.fields.created_at'))
                    ->dateTime(DeviceDateFormat::dateTime())
                    ->sortable(),
            ])
            ->filters([
                Filter::make('date')
                    ->schema([
                        DatePicker::make('date_from')->label(__('app.fields.date_from')),
                        DatePicker::make('date_to')->label(__('app.fields.date_to')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['date_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('checked_in_at', '>=', $date),
                            )
                            ->when(
                                $data['date_to'],
                                fn (Builder $query, $date): Builder => $query->whereDate('checked_in_at', '<=', $date),
                            );
                    }),
                SelectFilter::make('override')
                    ->label(__('app.activity.override'))
                    ->options([
                        0 => __('app.activity.normal'),
                        1 => __('app.activity.overridden'),
                    ]),
                SelectFilter::make('location_id')
                    ->label(__('app.fields.location'))
                    ->options(function () use ($accessible): array {
                        $query = Location::query();

                        if ($accessible !== null) {
                            $query->whereIn('id', $accessible);
                        }

                        return $query->orderBy('name')->pluck('name', 'id')->all();
                    }),
            ])
            ->defaultSort('checked_in_at', 'desc')
            ->emptyStateIcon('heroicon-o-clock')
            ->emptyStateHeading(__('app.activity.empty_heading'))
            ->emptyStateDescription(__('app.activity.empty_description'));
    }
}
