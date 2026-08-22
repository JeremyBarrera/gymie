<?php

namespace App\Filament\Widgets\Analytics\Concerns;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

/**
 * Shared rendering helpers for the analytics KPI stat widgets.
 */
trait RendersStatDeltas
{
    /**
     * Build an inline delta label for KPI cards.
     *
     * @return array{label: string, icon: string|null, class: string}
     */
    private function deltaInline(int|float $current, int|float $previous, callable $format): array
    {
        if ($previous <= 0) {
            if ($current <= 0) {
                return [
                    'label' => '0%',
                    'icon' => 'heroicon-o-minus',
                    'class' => 'text-gray-500',
                ];
            }

            return [
                'label' => $format($current),
                'icon' => 'heroicon-o-arrow-trending-up',
                'class' => 'text-success-600 dark:text-success-400',
            ];
        }

        $pct = (($current - $previous) / $previous) * 100;
        $pct = round($pct, 1);

        if ($pct === 0.0) {
            return [
                'label' => '0%',
                'icon' => 'heroicon-o-minus',
                'class' => 'text-gray-500',
            ];
        }

        if ($pct > 0) {
            return [
                'label' => "+{$pct}%",
                'icon' => 'heroicon-o-arrow-trending-up',
                'class' => 'text-success-600 dark:text-success-400',
            ];
        }

        $pct = abs($pct);

        return [
            'label' => "-{$pct}%",
            'icon' => 'heroicon-o-arrow-trending-down',
            'class' => 'text-danger-600 dark:text-danger-400',
        ];
    }

    /**
     * Render a KPI value with an inline delta.
     *
     * The row wraps so the delta drops below the value when space is tight,
     * and the value truncates with an ellipsis instead of escaping the card.
     *
     * @param  array{label: string, icon: string|null, class: string}  $delta
     */
    private function valueWithDelta(string $value, array $delta, string $margin = 'my-1'): HtmlString
    {
        $value = e($value);
        $deltaLabel = e($delta['label']);
        $deltaClass = e($delta['class']);

        $deltaIcon = null;
        if (filled($delta['icon'])) {
            $deltaIcon = Blade::render(
                '<x-filament::icon :icon="$icon" class="h-4 w-4 shrink-0 text-current" />',
                ['icon' => $delta['icon']],
            );
        }

        return new HtmlString(<<<HTML
<div class="flex flex-wrap items-baseline gap-x-2 gap-y-1 {$margin} min-w-0">
    <span class="min-w-0 truncate whitespace-nowrap">{$value}</span>
    <span class="text-sm font-semibold {$deltaClass} inline-flex shrink-0 items-center gap-1 whitespace-nowrap">{$deltaLabel}{$deltaIcon}</span>
</div>
HTML);
    }

    /**
     * Tailwind selector classes applied to the stat card root.
     *
     * Colors the main stat icon with the panel primary color and keeps the
     * description line inside the card (truncated with an ellipsis instead
     * of overflowing the card boundary).
     */
    private function statCardClasses(): string
    {
        return '[&_.fi-wi-stats-overview-stat-label-ctn>.fi-icon]:text-primary-400 dark:[&_.fi-wi-stats-overview-stat-label-ctn>.fi-icon]:text-primary-400 [&_.fi-wi-stats-overview-stat-description]:min-w-0 [&_.fi-wi-stats-overview-stat-description>span]:truncate [&_.fi-wi-stats-overview-stat-description>span]:whitespace-nowrap';
    }
}
