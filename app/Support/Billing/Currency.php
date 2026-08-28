<?php

namespace App\Support\Billing;

use App\Support\Data;
use Illuminate\Support\Number;
use NumberFormatter;

final class Currency
{
    

    public static function codeFromSettings(array $settings, string $defaultCode = 'INR'): string
    {
        $general = is_array($settings['general'] ?? null) ? $settings['general'] : [];
        $currency = $general['currency'] ?? null;

        return filled($currency) ? Data::string($currency, $defaultCode) : $defaultCode;
    }

    

    public static function format(?float $value, string $currencyCode): string
    {
        return (string) Number::currency($value ?? 0, $currencyCode, null, 0);
    }

    

    public static function symbol(string $currencyCode): string
    {
        $formatter = new NumberFormatter('en'."@currency={$currencyCode}", NumberFormatter::CURRENCY);

        return $formatter->getSymbol(NumberFormatter::CURRENCY_SYMBOL) ?: '';
    }
}
