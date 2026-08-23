<?php

use App\Contracts\SettingsRepository;
use App\Events\LocaleChanged;
use App\Filament\Livewire\LocaleSwitcher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('broadcasts the chosen locale when an authenticated admin updates it', function (): void {
    Event::fake([LocaleChanged::class]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/admin/locale', ['locale' => 'fr'])
        ->assertOk();

    Event::assertDispatched(LocaleChanged::class, function (LocaleChanged $event): bool {
        return $event->locale === 'fr';
    });
});

it('rejects locales outside the supported list', function (): void {
    Event::fake([LocaleChanged::class]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/admin/locale', ['locale' => 'de'])
        ->assertUnprocessable();

    Event::assertNotDispatched(LocaleChanged::class);
});

it('requires a locale value', function (): void {
    Event::fake([LocaleChanged::class]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/admin/locale', [])
        ->assertUnprocessable();

    Event::assertNotDispatched(LocaleChanged::class);
});

it('requires an authenticated admin', function (): void {
    Event::fake([LocaleChanged::class]);

    $this->postJson('/admin/locale', ['locale' => 'fr'])->assertUnauthorized();

    Event::assertNotDispatched(LocaleChanged::class);
});

it('broadcasts when an admin switches the locale via the switcher', function (): void {
    config()->set('app.supported_locales', ['en', 'fr', 'ar']);
    Event::fake([LocaleChanged::class]);

    app()->instance(SettingsRepository::class, new class implements SettingsRepository
    {
        public function get(): array
        {
            return [
                'general' => [
                    'locale' => 'en',
                ],
            ];
        }

        public function put(array $settings): void {}
    });

    app()->setLocale('en');

    Livewire::test(LocaleSwitcher::class)
        ->call('setLocale', 'ar')
        ->assertSet('locale', 'ar');

    Event::assertDispatched(LocaleChanged::class, function (LocaleChanged $event): bool {
        return $event->locale === 'ar';
    });
});

it('does not broadcast when the switcher receives an unsupported locale', function (): void {
    config()->set('app.supported_locales', ['en', 'fr', 'ar']);
    Event::fake([LocaleChanged::class]);

    app()->instance(SettingsRepository::class, new class implements SettingsRepository
    {
        public int $putCount = 0;

        public function get(): array
        {
            return [
                'general' => [
                    'locale' => 'en',
                ],
            ];
        }

        public function put(array $settings): void
        {
            $this->putCount++;
        }
    });

    app()->setLocale('en');

    Livewire::test(LocaleSwitcher::class)
        ->call('setLocale', 'de')
        ->assertSet('locale', 'en');

    Event::assertNotDispatched(LocaleChanged::class);
});
