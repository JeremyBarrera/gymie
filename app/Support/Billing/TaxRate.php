<?php

namespace App\Support\Billing;

final class TaxRate
{
    

    public static function fromSettings(array $settings): float
    {
        $charges = is_array($settings['charges'] ?? null) ? $settings['charges'] : [];
        $taxRate = $charges['taxes'] ?? 0.0;

        return is_numeric($taxRate) ? (float) $taxRate : 0.0;
    }
}
