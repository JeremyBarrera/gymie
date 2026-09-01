<?php

namespace App\Filament\Widgets\Analytics;

use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Helpers\Helpers;
use App\Models\Subscription;
use App\Support\AppConfig;
use App\Support\Locations\LocationAccess;
use Carbon\CarbonImmutable;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class ExpiringSoonSubscriptionsTableWidget extends TableWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = -39;

    protected static ?string $heading = null;

    protected int|string|array $columnSpan = [
        'default' => 1,
        'md' => 3,
    ];

    protected function getExpiringSoonQuery(): Builder
    {
        $today = CarbonImmutable::today(AppConfig::timezone());
        $end = $today->addDays(Helpers::getSubscriptionExpiringDays());
        $locationIds = LocationAccess::scopeFromFilters(auth()->user(), $this->pageFilters);

        return Subscription::query()
            ->with(['member', 'plan'])
            ->whereDate('start_date', '<=', $today->toDateString())
            ->whereDate('end_date', '>=', $today->toDateString())
            ->whereDate('end_date', '<=', $end->toDateString())
            ->when(
                $locationIds !== null,
                fn (Builder $query): Builder => $query->whereHas(
                    'member',
                    fn (Builder $query): Builder => LocationAccess::applyAccessibleScope($query, 'location_id', $locationIds),
                ),
            )
            ->orderBy('end_date');
    }

    private function formatDayCount(int $days): string
    {
        $unit = $days === 1 ? __('app.units.day') : __('app.units.days');

        return "{$days} {$unit}";
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('app.widgets.expiring_soon'))
            ->query(fn (): Builder => $this->getExpiringSoonQuery())
            ->columns([
                TextColumn::make('member.name')
                    ->label(__('app.fields.member'))
                    ->description(fn (Subscription $record): string => (string) ($record->member->code ?? ''))
                    ->wrap(),
                TextColumn::make('plan.name')
                    ->label(__('app.fields.plan'))
                    ->description(fn (Subscription $record): string => (string) ($record->plan->code ?? ''))
                    ->wrap(),
                TextColumn::make('end_date')
                    ->label(__('app.fields.end_date'))
                    ->date()
                    ->sortable(),
                TextColumn::make('days_left')
                    ->label(__('app.widgets.days_left'))
                    ->alignRight()
                    ->state(function (Subscription $record): string {
                        $today = CarbonImmutable::today(AppConfig::timezone());
                        $endDate = CarbonImmutable::parse($record->end_date, AppConfig::timezone())->startOfDay();

                        $days = (int) max($today->diffInDays($endDate, false), 0);

                        return $this->formatDayCount($days);
                    }),
            ])
            ->recordActions([
                ActionGroup::make([ViewAction::make()
                    ->url(fn (Subscription $record): string => SubscriptionResource::getUrl('view', ['record' => $record])),
                ]),
            ])
            ->emptyStateHeading(__('app.widgets.no_expiring_subscriptions'))
            ->emptyStateDescription(__('app.widgets.no_expiring_subscriptions_description'))
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50]);
    }
}
