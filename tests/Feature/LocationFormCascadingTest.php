<?php

use App\Filament\Resources\Locations\Schemas\LocationForm;
use App\Helpers\Helpers;
use App\Models\Location;
use Filament\Forms\Components\Select;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Component;

it('uses cascading world-data selects for country, state, and city', function (): void {
    $livewire = new class extends Component implements HasSchemas
    {
        use InteractsWithSchemas;

        public function render(): string
        {
            return '<div></div>';
        }
    };

    $schema = LocationForm::configure(Schema::make($livewire)->model(Location::class));

    $country = $schema->getComponentByStatePath('country');
    $state = $schema->getComponentByStatePath('state', withHidden: true);
    $city = $schema->getComponentByStatePath('city', withHidden: true);

    expect($country)->toBeInstanceOf(Select::class)
        ->and($state)->toBeInstanceOf(Select::class)
        ->and($city)->toBeInstanceOf(Select::class)
        ->and($country->getOptions())->toBe(Helpers::getCountries());
});

it('prevents deleting locations that still have members', function (): void {
    expect(config('prevent-deletion.'.Location::class))->toBe(['members']);
});

uses(RefreshDatabase::class);

it('hides state and city selects when there are no options, and reveals them when data exists', function (): void {
    $countryModel = config('world.models.countries');
    $stateModel = config('world.models.states');
    $cityModel = config('world.models.cities');

    $country = $countryModel::query()->create([
        'iso2' => 'TL',
        'iso3' => 'TST',
        'name' => 'Testland',
        'phone_code' => '1',
        'region' => 'Test',
        'subregion' => 'Test',
        'status' => 1,
    ]);
    $state = $stateModel::query()->create([
        'name' => 'Test State',
        'country_id' => $country->id,
    ]);
    $cityModel::query()->create([
        'name' => 'Test City',
        'state_id' => $state->id,
        'country_id' => $country->id,
        'country_code' => 'TL',
    ]);

    $livewire = new class extends Component implements HasSchemas
    {
        use InteractsWithSchemas;

        public function render(): string
        {
            return '<div></div>';
        }
    };

    $schema = LocationForm::configure(Schema::make($livewire)->model(Location::class));

    // With no country chosen, state and city are hidden.
    expect($schema->getComponentByStatePath('state'))->toBeNull()
        ->and($schema->getComponentByStatePath('city'))->toBeNull();

    // With a country + state chosen, the selects become visible.
    $livewire->country = 'Testland';
    $livewire->state = 'Test State';
    $schema = LocationForm::configure(Schema::make($livewire)->model(Location::class));

    $stateComponent = $schema->getComponentByStatePath('state');
    $cityComponent = $schema->getComponentByStatePath('city');

    expect($stateComponent)->toBeInstanceOf(Select::class)
        ->and($stateComponent->getOptions())->toBe(Helpers::getStates('Testland'))
        ->and($cityComponent)->toBeInstanceOf(Select::class)
        ->and($cityComponent->getOptions())->toBe(Helpers::getCities('Test State', 'Testland'));
});
