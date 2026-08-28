<?php

namespace App\Filament\Widgets\Analytics;

use App\Helpers\Helpers;
use App\Services\Analytics\AnalyticsService;
use App\Support\Analytics\AnalyticsDateRange;
use App\Support\AppConfig;
use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentColor;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

class ExpenseCategoriesDoughnutChartWidget extends Widget
{
    protected static ?int $sort = -39;

    

    protected string $view = 'filament.widgets.analytics.expense-spending-overview';

    

    public ?string $filter = '6months';

    

    protected int|string|array $columnSpan = [
        'default' => 1,
        'md' => 2,
    ];

    

    public function getFilters(): array
    {
        return [
            '7days' => __('app.analytics.ranges.7days'),
            '30days' => __('app.analytics.ranges.30days'),
            'quarter' => __('app.analytics.ranges.quarter'),
            '6months' => __('app.analytics.ranges.6months'),
            'ytd' => __('app.analytics.ranges.ytd'),
        ];
    }

    

    private function resolveRange(): AnalyticsDateRange
    {
        $rangeKey = $this->filter ?: '6months';

        $today = CarbonImmutable::today(AppConfig::timezone());
        $monthStart = $today->startOfMonth();

        [$start, $end] = match ($rangeKey) {
            '7days' => [$today->subDays(6), $today],
            '30days' => [$today->subDays(29), $today],
            'quarter' => [$today->startOfQuarter(), $today],
            'ytd' => [$today->startOfYear(), $today],
            default => [$monthStart->subMonthsNoOverflow(5), $today],
        };

        return new AnalyticsDateRange(
            $start->startOfDay(),
            $end->endOfDay(),
        );
    }

    

    private function colorShade(?array $palette, int|string $shade, string $fallback): string
    {
        if (is_array($palette)) {
            $value = $palette[$shade] ?? null;
            if (is_string($value) && filled($value)) {
                return $value;
            }

            $fallbackValue = $palette[500] ?? $palette['500'] ?? null;
            if (is_string($fallbackValue) && filled($fallbackValue)) {
                return $fallbackValue;
            }
        }

        return $fallback;
    }

    

    private function segmentPalette(): array
    {
        $primary = FilamentColor::getColor('primary');
        $info = FilamentColor::getColor('info');
        $warning = FilamentColor::getColor('warning');
        $success = FilamentColor::getColor('success');

        return [
            $this->colorShade($primary, 400, '#1c908d'),
            $this->colorShade($primary, 600, '#0e5c5a'),
            $this->colorShade($info, 500, '#3B82F6'),
            $this->colorShade($warning, 500, '#F97316'),
            $this->colorShade($success, 500, '#10B981'),
        ];
    }

    

    private function otherSegmentColor(): string
    {
        $gray = FilamentColor::getColor('gray');

        return $this->colorShade($gray, 400, '#94A3B8');
    }

    

    private function buildSegments(AnalyticsDateRange $range): Collection
    {
        $rows = app(AnalyticsService::class)->expenseCategoryBreakdownForChart($range, 5);

        $palette = $this->segmentPalette();
        $otherColor = $this->otherSegmentColor();

        return $rows
            ->values()
            ->map(function (array $row, int $index) use ($palette, $otherColor): array {
                $category = (string) $row['category'];
                $resolvedLabel = Helpers::getExpenseCategoryLabel($category);
                $label = (string) ($category === 'Other'
                    ? __('app.analytics.other')
                    : (is_string($resolvedLabel) && filled($resolvedLabel) ? $resolvedLabel : $category));

                return [
                    'label' => $label,
                    'total' => (float) $row['total'],
                    'flex' => (float) max((float) $row['total'], 0),
                    'color' => $category === 'Other'
                        ? $otherColor
                        : ($palette[$index] ?? $palette[max(array_key_last($palette) ?? 0, 0)]),
                ];
            })
            ->filter(fn (array $segment): bool => $segment['flex'] > 0)
            ->values();
    }

    

    protected function getViewData(): array
    {
        $range = $this->resolveRange();

        $service = app(AnalyticsService::class);
        $expenses = (float) $service->financialMetrics($range)['expenses'];

        $segments = $this->buildSegments($range);

        return [
            'heading' => __('app.widgets.total_expense'),
            'totalExpense' => Helpers::formatCurrency($expenses),
            'segments' => $segments,
        ];
    }
}
