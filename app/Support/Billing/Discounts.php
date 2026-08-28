<?php

namespace App\Support\Billing;

use App\Support\Data;
use Illuminate\Support\Number;

final class Discounts
{
    

    public static function optionsFromSettings(array $settings): array
    {
        $charges = is_array($settings['charges'] ?? null) ? $settings['charges'] : [];
        $discounts = $charges['discounts'] ?? [];
        if (! is_array($discounts)) {
            return [];
        }

        $options = [];
        foreach ($discounts as $value) {
            $value = Data::float($value);
            $options[(string) $value] = (string) Number::percentage($value);
        }

        return $options;
    }

    

    public static function amount(?float $discountPercent, ?float $fee): float
    {
        $fee = (float) ($fee ?? 0);
        $discountPercent = (float) ($discountPercent ?? 0);

        $discountAmount = 0.0;
        if ($discountPercent > 0) {
            $discountAmount = ($fee * $discountPercent) / 100;
        }

        return round($discountAmount, 2);
    }
}
