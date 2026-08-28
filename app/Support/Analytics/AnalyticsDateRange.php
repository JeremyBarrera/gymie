<?php

namespace App\Support\Analytics;

use App\Support\AppConfig;
use Carbon\CarbonImmutable;

final readonly class AnalyticsDateRange
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {}

    

    public static function fromFilters(?array $filters, ?string $timezone = null): self
    {
        $filters ??= [];
        $timezone ??= AppConfig::timezone();
        $today = CarbonImmutable::today($timezone);

        $period = is_string($filters['period'] ?? null) ? $filters['period'] : '7days';
        $startDate = $filters['startDate'] ?? null;
        $endDate = $filters['endDate'] ?? null;

        if ($period !== 'custom') {
            [$start, $end] = match ($period) {
                '7days' => [$today->subDays(6), $today],
                '30days' => [$today->subDays(29), $today],
                'month' => [$today->startOfMonth(), $today],
                'quarter' => [$today->startOfQuarter(), $today],
                'ytd', 'year' => [$today->startOfYear(), $today],
                default => [$today->subDays(6), $today],
            };

            return new self($start->startOfDay(), $end->endOfDay());
        }

        $start = is_string($startDate) ? CarbonImmutable::parse($startDate, $timezone) : $today->startOfMonth();
        $end = is_string($endDate) ? CarbonImmutable::parse($endDate, $timezone) : $today;

        $start = $start->startOfDay();
        $end = $end->endOfDay();

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end->startOfDay(), $start->endOfDay()];
        }

        return new self($start, $end);
    }

    

    public function referenceDateString(): string
    {
        return $this->end->toDateString();
    }
}
