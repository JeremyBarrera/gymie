<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Filament\Resources\Locations\Pages\CreateLocation;
use App\Filament\Resources\Locations\Pages\EditLocation;
use App\Helpers\Helpers;
use App\Models\Location;
use App\Models\User;
use App\Support\Billing\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CurrencyConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'owner']);
        Helpers::setTestSettingsOverride(['general' => []]);
    }

    protected function tearDown(): void
    {
        Helpers::setTestSettingsOverride(null);

        parent::tearDown();
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        $user->assignRole('owner');

        return $user;
    }

    private function locatedStaff(Location $location): User
    {
        $user = User::factory()->create();
        $user->locations()->syncWithoutDetaching([$location->id]);

        return $user;
    }

    private function storedGlobalCurrency(): ?string
    {
        return Helpers::getSettings()['general']['currency'] ?? null;
    }

    public function test_fallback_inr_when_nothing_set(): void
    {
        $this->assertTrue(Currency::isUnresolved(['general' => []]));
        $this->assertSame('INR', Helpers::getCurrencyCode());
    }

    public function test_global_currency_resolves_when_set(): void
    {
        Helpers::setTestSettingsOverride(['general' => ['currency' => 'EUR']]);

        $this->assertFalse(Currency::isUnresolved(Helpers::getSettings()));
        $this->assertSame('EUR', Helpers::getCurrencyCode());
        $this->assertStringContainsString('€', Helpers::formatCurrency(100));
    }

    public function test_location_override_beats_global_for_located_staff(): void
    {
        Helpers::setTestSettingsOverride(['general' => ['currency' => 'EUR']]);
        $location = Location::create(['name' => 'Main Gym', 'currency' => 'USD']);

        $this->actingAs($this->locatedStaff($location));

        $this->assertSame('USD', Helpers::getCurrencyCode());
    }

    public function test_create_location_prompts_when_unresolved(): void
    {
        Livewire::actingAs($this->owner())
            ->test(CreateLocation::class)
            ->assertNotified(__('app.currency.setup_title'));
    }

    public function test_create_location_silent_when_resolved(): void
    {
        Helpers::setTestSettingsOverride(['general' => ['currency' => 'EUR']]);

        Livewire::actingAs($this->owner())
            ->test(CreateLocation::class)
            ->assertNotNotified()
            ->assertSuccessful();
    }

    public function test_setup_prompt_saves_global(): void
    {
        Livewire::actingAs($this->owner())
            ->test(CreateLocation::class)
            ->callAction('chooseCurrency', ['currency' => 'ARS']);

        $this->assertSame('ARS', $this->storedGlobalCurrency());
        $this->assertSame('ARS', Helpers::getCurrencyCode());
    }

    public function test_create_confirm_decision(): void
    {
        $component = Livewire::actingAs($this->owner())->test(CreateLocation::class);

        $this->assertFalse($component->instance()->currencyConfirmRequired());

        $component->fillForm(['name' => 'Branch Gym', 'currency' => 'USD']);

        $this->assertTrue($component->instance()->currencyConfirmRequired());
    }

    public function test_create_with_currency_persists(): void
    {
        Livewire::actingAs($this->owner())
            ->test(CreateLocation::class)
            ->fillForm(['name' => 'Branch Gym', 'currency' => 'USD'])
            ->call('create');

        $this->assertDatabaseHas('locations', ['name' => 'Branch Gym', 'currency' => 'USD']);
    }

    public function test_create_without_currency_saves_directly(): void
    {
        Livewire::actingAs($this->owner())
            ->test(CreateLocation::class)
            ->fillForm(['name' => 'Plain Gym'])
            ->call('create')
            ->assertSuccessful();

        $this->assertDatabaseHas('locations', ['name' => 'Plain Gym']);
    }

    public function test_edit_confirm_decision(): void
    {
        $location = Location::create(['name' => 'Main Gym', 'currency' => 'USD']);

        $component = Livewire::actingAs($this->owner())
            ->test(EditLocation::class, ['record' => $location->getRouteKey()]);

        $this->assertFalse($component->instance()->currencyChanged());

        $component->set('data.currency', 'EUR');

        $this->assertTrue($component->instance()->currencyChanged());

        $component->set('data.currency', null);

        $this->assertTrue($component->instance()->currencyChanged());
    }

    public function test_edit_save_persists(): void
    {
        $location = Location::create(['name' => 'Main Gym', 'currency' => 'USD']);

        Livewire::actingAs($this->owner())
            ->test(EditLocation::class, ['record' => $location->getRouteKey()])
            ->set('data.currency', 'EUR')
            ->call('save');

        $this->assertSame('EUR', $location->fresh()->currency);
    }

    public function test_settings_change_opens_modal_then_confirms(): void
    {
        $stored = $this->storedGlobalCurrency();

        $component = Livewire::actingAs($this->owner())
            ->test(Settings::class)
            ->set('data.general.currency', 'EUR')
            ->call('save')
            ->assertDispatched('open-modal', id: 'currency-change-modal');

        $this->assertSame($stored, $this->storedGlobalCurrency());

        $component->call('confirmCurrencySave');

        $this->assertSame('EUR', $this->storedGlobalCurrency());
    }

    public function test_settings_cancel_reverts(): void
    {
        $stored = $this->storedGlobalCurrency();

        Livewire::actingAs($this->owner())
            ->test(Settings::class)
            ->set('data.general.currency', 'EUR')
            ->call('save')
            ->assertDispatched('open-modal', id: 'currency-change-modal')
            ->call('cancelCurrencySave')
            ->assertDispatched('close-modal', id: 'currency-change-modal')
            ->assertSet('data.general.currency', $stored);

        $this->assertSame($stored, $this->storedGlobalCurrency());
    }

    public function test_settings_unchanged_saves_without_modal(): void
    {
        Livewire::actingAs($this->owner())
            ->test(Settings::class)
            ->call('save')
            ->assertNotDispatched('open-modal');
    }

    public function test_invalid_currency_rejected(): void
    {
        Livewire::actingAs($this->owner())
            ->test(CreateLocation::class)
            ->fillForm(['name' => 'Bad Gym', 'currency' => 'XX'])
            ->call('create')
            ->assertHasFormErrors(['currency']);

        $this->assertDatabaseMissing('locations', ['name' => 'Bad Gym']);
    }

    public function test_currency_keys_resolve_in_all_locales(): void
    {
        $keys = [
            'app.currency.setup_title',
            'app.currency.setup_description',
            'app.currency.setup_save_default',
            'app.currency.setup_decide_later',
            'app.currency.change_heading',
            'app.currency.change_description',
            'app.currency.confirm_change',
            'app.currency.keep_current',
            'app.placeholders.select_currency',
            'app.placeholders.use_global_currency',
            'app.placeholders.currency_override_hint',
            'app.settings.tabs.general',
            'app.fields.currency',
        ];

        foreach (['en', 'ar', 'es', 'fa', 'fr'] as $locale) {
            foreach ($keys as $key) {
                $this->assertNotSame($key, __($key, [], $locale), "Missing {$key} in {$locale}");
            }
        }

        $this->assertStringContainsString(':code', __('app.placeholders.currency_override_hint', [], 'en'));
    }
}
