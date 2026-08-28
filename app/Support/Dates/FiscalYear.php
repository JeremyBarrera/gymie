<?php

namespace App\Support\Dates;

use App\Support\Data;
use Carbon\Carbon;
use Carbon\CarbonInterface;

final class FiscalYear
{
    

    public static function spanForDate(CarbonInterface $date, array $generalSettings): array
    {
        $tpl = self::parseTemplates($generalSettings);

        $year = $date->year;
        $start = Carbon::parse(sprintf('%04d-%02d-%02d', $year, $tpl['start']->month, $tpl['start']->day))->startOfDay();
        $endYear = $tpl['end']->lessThan($tpl['start']) ? $year + 1 : $year;
        $end = Carbon::parse(sprintf('%04d-%02d-%02d', $endYear, $tpl['end']->month, $tpl['end']->day))->startOfDay();

        if ($date->lt($start)) {
            $start = $start->subYear();
            $end = $end->subYear();
        }

        return [$start, $end];
    }

    

    private static function parseTemplates(array $generalSettings): array
    {
        $start = self::parseTemplateMonthDay($generalSettings['financial_year_start'] ?? null, 4, 1);
        $end = self::parseTemplateMonthDay($generalSettings['financial_year_end'] ?? null, 3, 31);

        return ['start' => $start, 'end' => $end];
    }

    private static function parseTemplateMonthDay(mixed $value, int $fallbackMonth, int $fallbackDay): Carbon
    {
        $month = $fallbackMonth;
        $day = $fallbackDay;

        try {
            if (filled($value)) {
                $parsed = Carbon::parse(Data::string($value));
                $month = $parsed->month;
                $day = $parsed->day;
            }
        } catch (\Throwable) {
            
        }

        return Carbon::parse(sprintf('2000-%02d-%02d', $month, $day))->startOfDay();
    }
}
