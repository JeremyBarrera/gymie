<?php

namespace App\Filament\Pages;

use App\Models\Location;
use App\Models\QueueEntry;
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
                QueueEntry::query()
                    ->with(['location', 'claimedBy'])
                    ->where('kind', 'checkin')
                    ->when($accessible !== null, fn (Builder $query): Builder => $query->whereIn('location_id', $accessible))
                    ->latest('created_at')
            )
            ->columns([
                TextColumn::make('id')
                    ->label(__('app.fields.code'))
                    ->formatStateUsing(fn (int $state): string => "#{$state}")
                    ->sortable(),
                TextColumn::make('member.name')
                    ->label(__('app.fields.name'))
                    ->searchable()
                    ->getStateUsing(fn (QueueEntry $record): ?string => $record->member?->name)
                    ->placeholder(__('app.placeholders.dash')),
                TextColumn::make('plan.name')
                    ->label(__('app.resources.plans.singular'))
                    ->getStateUsing(fn (QueueEntry $record): ?string => $record->plan?->name)
                    ->placeholder(__('app.placeholders.dash')),
                TextColumn::make('location.name')
                    ->label(__('app.fields.location'))
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('app.fields.status'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'attending' => 'warning',
                        'denied' => 'danger',
                        'expired' => 'gray',
                        default => 'info',
                    })
                    ->formatStateUsing(fn (string $state): string => __("app.reception.status.{$state}")),
                TextColumn::make('override')
                    ->label(__('app.activity.override'))
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'warning' : 'gray')
                    ->formatStateUsing(fn (bool $state): string => $state ? __('app.activity.overridden') : __('app.activity.normal')),
                TextColumn::make('claimedBy.name')
                    ->label(__('app.fields.checked_in_by'))
                    ->getStateUsing(fn (QueueEntry $record): ?string => $record->claimedBy?->name)
                    ->placeholder(__('app.placeholders.dash')),
                TextColumn::make('created_at')
                    ->label(__('app.fields.created_at'))
                    ->dateTime(DeviceDateFormat::dateTime())
                    ->sortable(),
                TextColumn::make('checked_in_at')
                    ->label(__('app.activity.checked_in_at'))
                    ->getStateUsing(fn (QueueEntry $record): ?string => $record->status === 'approved' ? $record->created_at : null)
                    ->dateTime(DeviceDateFormat::dateTime())
                    ->placeholder(__('app.placeholders.dash')),
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
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['date_to'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
                SelectFilter::make('status')
                    ->label(__('app.fields.status'))
                    ->options([
                        'waiting' => __('app.reception.status.waiting'),
                        'attending' => __('app.reception.status.attending'),
                        'approved' => __('app.reception.status.approved'),
                        'denied' => __('app.reception.status.denied'),
                        'expired' => __('app.reception.status.expired'),
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
            ->defaultSort('created_at', 'desc')
            ->emptyStateIcon('heroicon-o-clock')
            ->emptyStateHeading(__('app.activity.empty_heading'))
            ->emptyStateDescription(__('app.activity.empty_description'));
    }
}
