<?php

namespace App\Support\Billing;

use App\Helpers\Helpers;
use App\Models\Location;
use App\Support\Data;
use Illuminate\Support\Number;
use NumberFormatter;

final class Currency
{
    public static function codes(): array
    {
        return array_values(array_unique(array_map(
            fn (mixed $code): string => strtoupper(trim((string) $code)),
            array_keys(Helpers::getCurrencies())
        )));
    }

    public static function isUnresolved(array $settings): bool
    {
        if (filled($settings['general']['currency'] ?? null)) {
            return false;
        }

        return ! Location::query()
            ->whereNotNull('currency')
            ->where('currency', '!=', '')
            ->exists();
    }

    

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
