<?php

namespace App\Filament\Widgets\Analytics;

use App\Filament\Widgets\Analytics\Concerns\RendersStatDeltas;
use App\Helpers\Helpers;
use App\Services\Analytics\AnalyticsService;
use App\Support\Analytics\AnalyticsDateRange;
use App\Support\Locations\LocationAccess;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Financial overview widget for key revenue and expense KPIs.
 */
class FinancialMetricsWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters;
    use RendersStatDeltas;

    protected static ?int $sort = -40;

    /**
     * @var int | string | array<string, int | null>
     */
    protected int|string|array $columnSpan = [
        'default' => 1,
        'md' => 2,
    ];

    /**
     * @var int | array<string, ?int> | null
     */
    protected int|array|null $columns = 2;

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $range = AnalyticsDateRange::fromFilters($this->pageFilters);
        $locationIds = LocationAccess::scopeFromFilters(auth()->user(), $this->pageFilters);
        $service = app(AnalyticsService::class);
        $metrics = $service->financialMetrics($range, $locationIds);

        $days = $range->end->diffInDays($range->start) + 1;
        $previousRange = new AnalyticsDateRange(
            $range->start->subDays($days),
            $range->end->subDays($days),
        );
        $previous = $service->financialMetrics($previousRange, $locationIds);

        $netRevenueDelta = $this->deltaInline($metrics['net_revenue'], $previous['net_revenue'], fn (float $amount): string => '+'.Helpers::formatCurrency($amount));
        $collectedDelta = $this->deltaInline($metrics['collected'], $previous['collected'], fn (float $amount): string => '+'.Helpers::formatCurrency($amount));
        $outstandingDelta = $this->deltaInline($metrics['outstanding'], $previous['outstanding'], fn (float $amount): string => '+'.Helpers::formatCurrency($amount));
        $profitDelta = $this->deltaInline($metrics['profit'], $previous['profit'], fn (float $amount): string => '+'.Helpers::formatCurrency($amount));

        return [
            Stat::make(
                __('app.widgets.net_revenue'),
                $this->valueWithDelta(Helpers::formatCurrency($metrics['net_revenue']), $netRevenueDelta, 'my-2'),
            )
                ->icon('heroicon-o-banknotes')
                ->extraAttributes(['class' => $this->statCardClasses()])
                ->description(__('app.widgets.vs_previous_period', ['count' => Helpers::formatCurrency($previous['net_revenue'])]))
                ->descriptionColor('gray'),
            Stat::make(
                __('app.widgets.total_collected'),
                $this->valueWithDelta(Helpers::formatCurrency($metrics['collected']), $collectedDelta, 'my-2'),
            )
                ->icon('heroicon-o-arrow-down-tray')
                ->extraAttributes(['class' => $this->statCardClasses()])
                ->description(__('app.widgets.vs_previous_period', ['count' => Helpers::formatCurrency($previous['collected'])]))
                ->descriptionColor('gray'),
            Stat::make(
                __('app.widgets.outstanding_payments'),
                $this->valueWithDelta(Helpers::formatCurrency($metrics['outstanding']), $outstandingDelta, 'my-2'),
            )
                ->icon('heroicon-o-clock')
                ->extraAttributes(['class' => $this->statCardClasses()])
                ->description(__('app.widgets.vs_previous_period', ['count' => Helpers::formatCurrency($previous['outstanding'])]))
                ->descriptionColor('gray'),
            Stat::make(
                __('app.widgets.profit'),
                $this->valueWithDelta(Helpers::formatCurrency($metrics['profit']), $profitDelta, 'my-2'),
            )
                ->icon('heroicon-o-chart-bar-square')
                ->extraAttributes(['class' => $this->statCardClasses()])
                ->description(__('app.widgets.vs_previous_period', ['count' => Helpers::formatCurrency($previous['profit'])]))
                ->descriptionColor('gray'),
        ];
    }
}
