<?php

namespace App\Support\Dates;

use App\Support\AppConfig;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;

final class DeviceDateFormat
{
    public const COOKIE_ORDER = 'gymie_date_order';

    public const COOKIE_HOUR12 = 'gymie_hour12';

    public const COOKIE_TIMEZONE = 'gymie_device_tz';

    private const ORDERS = ['mdy', 'dmy', 'ymd'];

    private static ?bool $consoleOverride = null;

    

    public static function setTestConsoleOverride(?bool $console): void
    {
        self::$consoleOverride = $console;
    }

    

    public static function order(): string
    {
        $cookie = self::request()?->cookie(self::COOKIE_ORDER);

        if (is_string($cookie) && in_array($cookie, self::ORDERS, true)) {
            return $cookie;
        }

        return self::orderFromLocale(self::browserLocale());
    }

    

    public static function hour12(): bool
    {
        $cookie = self::request()?->cookie(self::COOKIE_HOUR12);

        if ($cookie === '1' || $cookie === '0') {
            return $cookie === '1';
        }

        return self::hour12FromLocale(self::browserLocale());
    }

    

    public static function date(): string
    {
        return match (self::order()) {
            'mdy' => 'm/d/Y',
            'ymd' => 'Y/m/d',
            default => 'd/m/Y',
        };
    }

    

    public static function time(): string
    {
        return self::hour12() ? 'h:i A' : 'H:i';
    }

    

    public static function dateTime(): string
    {
        return self::date().' '.self::time();
    }

    

    public static function timezone(): string
    {
        $cookie = self::request()?->cookie(self::COOKIE_TIMEZONE);

        if (is_string($cookie) && $cookie !== '') {
            try {
                new \DateTimeZone($cookie);

                return $cookie;
            } catch (\Throwable) {
                
            }
        }

        return AppConfig::timezone();
    }

    

    public static function format(?CarbonInterface $date): string
    {
        return $date?->copy()->timezone(self::timezone())->translatedFormat(self::date()) ?? '—';
    }

    

    public static function formatTime(?CarbonInterface $date): string
    {
        return $date?->copy()->timezone(self::timezone())->translatedFormat(self::time()) ?? '—';
    }

    

    public static function formatDateTime(?CarbonInterface $date): string
    {
        return $date?->copy()->timezone(self::timezone())->translatedFormat(self::dateTime()) ?? '—';
    }

    private static function request(): ?Request
    {
        $app = app();

        
        
        
        if (self::$consoleOverride ?? $app->runningInConsole()) {
            return null;
        }

        if (! $app->bound('request')) {
            return null;
        }

        $request = $app->make('request');

        return $request instanceof Request ? $request : null;
    }

    private static function browserLocale(): string
    {
        $languages = self::request()?->getLanguages() ?? [];
        $first = $languages[0] ?? null;

        if (is_string($first) && $first !== '') {
            return $first;
        }

        return (string) app()->getLocale();
    }

    private static function orderFromLocale(string $locale): string
    {
        [$language, $region] = self::languageAndRegion($locale);

        if ($language === 'fa') {
            return 'ymd';
        }

        if ($language === 'en' && in_array($region, ['US', 'PH'], true)) {
            return 'mdy';
        }

        return 'dmy';
    }

    private static function hour12FromLocale(string $locale): bool
    {
        [$language, $region] = self::languageAndRegion($locale);

        if ($language === 'en') {
            return true;
        }

        if ($language === 'es') {
            return $region !== 'ES';
        }

        return false;
    }

    

    private static function languageAndRegion(string $locale): array
    {
        $parts = explode('-', str_replace('_', '-', $locale));

        return [
            strtolower($parts[0] ?? ''),
            strtoupper($parts[1] ?? ''),
        ];
    }
}
