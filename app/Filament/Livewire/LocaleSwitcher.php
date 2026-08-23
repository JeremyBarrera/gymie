<?php

namespace App\Filament\Livewire;

use App\Contracts\SettingsRepository;
use App\Events\LocaleChanged;
use App\Support\AppConfig;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class LocaleSwitcher extends Component
{
    public string $locale = 'en';

    public function mount(): void
    {
        $this->locale = AppConfig::string('app.locale', 'en');
    }

    /**
     * @return array<string, array{label: string, flag: string}>
     */
    public function getOptionsProperty(): array
    {
        $options = [];

        foreach (AppConfig::supportedLocales() as $locale) {
            $options[$locale] = [
                'label' => (string) __("app.locales.{$locale}"),
                'flag' => AppConfig::localeFlags()[$locale] ?? '🏳️',
            ];
        }

        return $options;
    }

    public function getCurrentFlagProperty(): string
    {
        return AppConfig::localeFlags()[$this->locale] ?? '🏳️';
    }

    public function setLocale(string $locale): mixed
    {
        $options = $this->getOptionsProperty();

        if (! array_key_exists($locale, $options)) {
            return null;
        }

        /** @var SettingsRepository $repository */
        $repository = app(SettingsRepository::class);

        $settings = $repository->get();
        data_set($settings, 'general.locale', $locale);
        /** @var array<string, mixed> $settings */
        $repository->put($settings);

        LocaleChanged::dispatch($locale);

        $this->locale = $locale;

        app()->setLocale($locale);

        Notification::make()
            ->title(__('app.notifications.language_updated'))
            ->success()
            ->send();

        $referer = request()->headers->get('referer');
        $redirectTo = is_string($referer) && $referer !== '' ? $referer : url('/');

        return redirect()->to($redirectTo);
    }

    public function render(): View
    {
        return view('filament.components.locale-switcher');
    }
}
