<?php

use App\Contracts\SettingsRepository;
use App\Http\Middleware\SetAppLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

test('it syncs Carbon locale with app locale', function () {
    $originalAppLocale = app()->getLocale();
    $originalCarbonLocale = Carbon::getLocale();

    try {
        config()->set('app.locale', 'en');
        config()->set('app.fallback_locale', 'en');
        config()->set('app.supported_locales', ['en', 'fr', 'ar']);

        app()->bind(SettingsRepository::class, fn (): SettingsRepository => new class implements SettingsRepository
        {
            public function get(): array
            {
                return [];
            }

            public function put(array $settings): void {}
        });

        $request = Request::create('/?locale=fr');
        $middleware = new SetAppLocale;

        $middleware->handle($request, fn (Request $request) => response()->noContent());

        expect(app()->getLocale())->toBe('fr')
            ->and(Carbon::getLocale())->toBe('fr');
    } finally {
        app()->setLocale($originalAppLocale);
        Carbon::setLocale($originalCarbonLocale);
    }
});

function bindSettingsRepository(?string $locale): void
{
    app()->bind(SettingsRepository::class, fn (): SettingsRepository => new class($locale) implements SettingsRepository
    {
        public function __construct(private readonly ?string $locale) {}

        public function get(): array
        {
            return [
                'general' => [
                    'locale' => $this->locale,
                ],
            ];
        }

        public function put(array $settings): void {}
    });
}

function runLocaleMiddleware(Request $request): void
{
    (new SetAppLocale)->handle($request, fn (Request $request) => response()->noContent());
}

test('the device-language cookie beats the Accept-Language header when no preset is saved', function () {
    $originalAppLocale = app()->getLocale();
    $originalCarbonLocale = Carbon::getLocale();

    try {
        config()->set('app.locale', 'en');
        config()->set('app.fallback_locale', 'en');
        config()->set('app.supported_locales', ['en', 'fr', 'ar']);

        bindSettingsRepository(null);

        $request = Request::create('/admin', 'GET', [], [SetAppLocale::COOKIE_DEVICE_LOCALE => 'ar'], [], ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR,fr;q=0.9']);

        runLocaleMiddleware($request);

        expect(app()->getLocale())->toBe('ar')
            ->and(Carbon::getLocale())->toBe('ar');
    } finally {
        app()->setLocale($originalAppLocale);
        Carbon::setLocale($originalCarbonLocale);
    }
});

test('a saved locale preset always wins over the device language', function () {
    $originalAppLocale = app()->getLocale();
    $originalCarbonLocale = Carbon::getLocale();

    try {
        config()->set('app.locale', 'en');
        config()->set('app.fallback_locale', 'en');
        config()->set('app.supported_locales', ['en', 'fr', 'ar']);

        bindSettingsRepository('fr');

        $request = Request::create('/admin', 'GET', [], [SetAppLocale::COOKIE_DEVICE_LOCALE => 'ar'], [], ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR,fr;q=0.9']);

        runLocaleMiddleware($request);

        expect(app()->getLocale())->toBe('fr')
            ->and(Carbon::getLocale())->toBe('fr');
    } finally {
        app()->setLocale($originalAppLocale);
        Carbon::setLocale($originalCarbonLocale);
    }
});

test('an unsupported device-language cookie value is ignored', function () {
    $originalAppLocale = app()->getLocale();
    $originalCarbonLocale = Carbon::getLocale();

    try {
        config()->set('app.locale', 'en');
        config()->set('app.fallback_locale', 'en');
        config()->set('app.supported_locales', ['en', 'fr', 'ar']);

        bindSettingsRepository(null);

        $request = Request::create('/admin', 'GET', [], [SetAppLocale::COOKIE_DEVICE_LOCALE => 'de'], [], ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR,fr;q=0.9']);

        runLocaleMiddleware($request);

        expect(app()->getLocale())->toBe('fr')
            ->and(Carbon::getLocale())->toBe('fr');
    } finally {
        app()->setLocale($originalAppLocale);
        Carbon::setLocale($originalCarbonLocale);
    }
});

test('public routes ignore the device-language cookie', function () {
    $originalAppLocale = app()->getLocale();
    $originalCarbonLocale = Carbon::getLocale();

    try {
        config()->set('app.locale', 'en');
        config()->set('app.fallback_locale', 'en');
        config()->set('app.supported_locales', ['en', 'fr', 'ar']);

        bindSettingsRepository(null);

        $request = Request::create('/checkin/some-token', 'GET', [], [SetAppLocale::COOKIE_DEVICE_LOCALE => 'ar']);

        runLocaleMiddleware($request);

        expect(app()->getLocale())->toBe('en')
            ->and(Carbon::getLocale())->toBe('en');
    } finally {
        app()->setLocale($originalAppLocale);
        Carbon::setLocale($originalCarbonLocale);
    }
});
