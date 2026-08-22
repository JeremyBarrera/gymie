<?php

namespace App\Support\Dates;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;

/**
 * Resolves the user's preferred date/time display convention.
 *
 * The browser knows the device's own conventions (MM/DD/YYYY vs
 * DD/MM/YYYY, 12h vs 24h clock) through Intl; `resources/js/device-locale.js`
 * records the detected convention in cookies, which this class reads. Until
 * those cookies exist (first visit, queued emails, console commands) the
 * convention is inferred from the browser's Accept-Language header, then the
 * app locale.
 */
final class DeviceDateFormat
{
    public const COOKIE_ORDER = 'gymie_date_order';

    public const COOKIE_HOUR12 = 'gymie_hour12';

    private const ORDERS = ['mdy', 'dmy', 'ymd'];

    private static ?bool $consoleOverride = null;

    /**
     * Test override for the running-in-console flag.
     */
    public static function setTestConsoleOverride(?bool $console): void
    {
        self::$consoleOverride = $console;
    }

    /**
     * Date component order: 'mdy', 'dmy' or 'ymd'.
     */
    public static function order(): string
    {
        $cookie = self::request()?->cookie(self::COOKIE_ORDER);

        if (is_string($cookie) && in_array($cookie, self::ORDERS, true)) {
            return $cookie;
        }

        return self::orderFromLocale(self::browserLocale());
    }

    /**
     * Whether the user's device prefers a 12-hour clock.
     */
    public static function hour12(): bool
    {
        $cookie = self::request()?->cookie(self::COOKIE_HOUR12);

        if ($cookie === '1' || $cookie === '0') {
            return $cookie === '1';
        }

        return self::hour12FromLocale(self::browserLocale());
    }

    /**
     * PHP date() format string matching the device convention.
     */
    public static function date(): string
    {
        return match (self::order()) {
            'mdy' => 'm/d/Y',
            'ymd' => 'Y/m/d',
            default => 'd/m/Y',
        };
    }

    /**
     * PHP time() format string matching the device clock convention.
     */
    public static function time(): string
    {
        return self::hour12() ? 'h:i A' : 'H:i';
    }

    /**
     * Combined PHP date + time format string.
     */
    public static function dateTime(): string
    {
        return self::date().' '.self::time();
    }

    /**
     * Render a date in the device convention with locale-translated parts.
     */
    public static function format(?CarbonInterface $date): string
    {
        return $date?->translatedFormat(self::date()) ?? '—';
    }

    /**
     * Render a time in the device clock convention.
     */
    public static function formatTime(?CarbonInterface $date): string
    {
        return $date?->translatedFormat(self::time()) ?? '—';
    }

    /**
     * Render a date + time in the device conventions.
     */
    public static function formatDateTime(?CarbonInterface $date): string
    {
        return $date?->translatedFormat(self::dateTime()) ?? '—';
    }

    private static function request(): ?Request
    {
        $app = app();

        // In console/queue context Laravel binds a synthetic request whose
        // Accept-Language defaults to "en-us"; only real HTTP requests carry
        // a meaningful browser language, so ignore it there.
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

    /**
     * Split a locale like "en-US" into language and region parts.
     *
     * @return array{0: string, 1: string}
     */
    private static function languageAndRegion(string $locale): array
    {
        $parts = explode('-', str_replace('_', '-', $locale));

        return [
            strtolower($parts[0] ?? ''),
            strtoupper($parts[1] ?? ''),
        ];
    }
}
