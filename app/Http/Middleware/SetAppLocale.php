<?php

namespace App\Http\Middleware;

use App\Contracts\SettingsRepository;
use App\Support\AppConfig;
use App\Support\Data;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class SetAppLocale
{
    public const COOKIE_DEVICE_LOCALE = 'gymie_device_locale';

    

    public function handle(Request $request, Closure $next): Response
    {
        $supportedLocales = AppConfig::supportedLocales();
        $fallbackLocale = AppConfig::string('app.fallback_locale', 'en');

        $queryLocale = $request->query('locale');
        $queryLocale = is_string($queryLocale) ? trim($queryLocale) : null;

        $settingsLocale = null;
        try {
            $settings = app(SettingsRepository::class)->get();
            $candidate = data_get($settings, 'general.locale');
            $settingsLocale = is_string($candidate) ? trim($candidate) : null;
        } catch (\Throwable) {
            $settingsLocale = null;
        }

        $headerLocale = $request->getPreferredLanguage($supportedLocales);
        $headerLocale = is_string($headerLocale) ? trim($headerLocale) : null;

        
        
        
        $cookieLocale = $request->cookie(self::COOKIE_DEVICE_LOCALE);
        $cookieLocale = is_string($cookieLocale) ? strtolower(trim($cookieLocale)) : '';
        $cookieLocale = in_array($cookieLocale, $supportedLocales, true) ? $cookieLocale : null;

        $isPublicRoute = str_starts_with($request->path(), 'checkin')
            || str_starts_with($request->path(), 'signup')
            || str_starts_with($request->path(), 'waiting');

        if ($isPublicRoute) {
            $locale = $queryLocale ?: ($headerLocale ?: AppConfig::string('app.locale', 'en'));
        } else {
            $locale = $queryLocale
                ?: ($settingsLocale
                    ?: ($cookieLocale
                        ?: ($headerLocale ?: AppConfig::string('app.locale', 'en'))));
        }

        if (! in_array($locale, $supportedLocales, true)) {
            $locale = in_array($fallbackLocale, $supportedLocales, true)
                ? $fallbackLocale
                : Data::string($supportedLocales[0] ?? 'en', 'en');
        }

        app()->setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }
}
