<?php

namespace App\Filament\Widgets\Analytics;

use App\Filament\Widgets\Analytics\Concerns\RendersStatDeltas;
use App\Services\Analytics\AnalyticsService;
use App\Support\Analytics\AnalyticsDateRange;
use App\Support\Locations\LocationAccess;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Membership insights widget.
 */
class MembershipMetricsWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters;
    use RendersStatDeltas;

    protected static ?int $sort = -41;

    protected int|string|array $columnSpan = 'full';

    /**
     * @var int | array<string, ?int> | null
     */
    protected int|array|null $columns = 4;

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $range = AnalyticsDateRange::fromFilters($this->pageFilters);
        $locationIds = LocationAccess::scopeFromFilters(auth()->user(), $this->pageFilters);
        $service = app(AnalyticsService::class);
        $metrics = $service->membershipMetrics($range, $locationIds);

        $days = $range->end->diffInDays($range->start) + 1;
        $previousRange = new AnalyticsDateRange(
            $range->start->subDays($days),
            $range->end->subDays($days),
        );
        $previous = $service->membershipMetrics($previousRange, $locationIds);

        $activeDelta = $this->deltaInline($metrics['active_members'], $previous['active_members'], fn (int $count): string => "+{$count}");
        $signupDelta = $this->deltaInline($metrics['new_signups'], $previous['new_signups'], fn (int $count): string => "+{$count}");
        $renewalDelta = $this->deltaInline($metrics['renewals'], $previous['renewals'], fn (int $count): string => "+{$count}");
        $expiredDelta = $this->deltaInline($metrics['expired_not_renewed'], $previous['expired_not_renewed'], fn (int $count): string => "+{$count}");

        return [
            Stat::make(
                __('app.widgets.active_members'),
                $this->valueWithDelta((string) $metrics['active_members'], $activeDelta),
            )
                ->icon('heroicon-o-user-group')
                ->extraAttributes(['class' => $this->statCardClasses()])
                ->description(__('app.widgets.vs_previous_period', ['count' => (string) $previous['active_members']]))
                ->descriptionColor('gray'),
            Stat::make(
                __('app.widgets.new_members'),
                $this->valueWithDelta((string) $metrics['new_signups'], $signupDelta),
            )
                ->icon('heroicon-o-user-plus')
                ->extraAttributes(['class' => $this->statCardClasses()])
                ->description(__('app.widgets.vs_previous_period', ['count' => (string) $previous['new_signups']]))
                ->descriptionColor('gray'),
            Stat::make(
                __('app.widgets.renewals'),
                $this->valueWithDelta((string) $metrics['renewals'], $renewalDelta),
            )
                ->icon('heroicon-o-arrow-path')
                ->extraAttributes(['class' => $this->statCardClasses()])
                ->description(__('app.widgets.vs_previous_period', ['count' => (string) $previous['renewals']]))
                ->descriptionColor('gray'),
            Stat::make(
                __('app.widgets.expired_not_renewed'),
                $this->valueWithDelta((string) $metrics['expired_not_renewed'], $expiredDelta),
            )
                ->icon('heroicon-o-x-circle')
                ->extraAttributes(['class' => $this->statCardClasses()])
                ->description(__('app.widgets.vs_previous_period', ['count' => (string) $previous['expired_not_renewed']]))
                ->descriptionColor('gray'),
        ];
    }
}
