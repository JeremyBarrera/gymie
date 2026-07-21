<?php

use App\Filament\Resources\Locations\Schemas\LocationForm;
use App\Helpers\Helpers;
use App\Models\Location;
use Filament\Forms\Components\Select;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
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
    $state = $schema->getComponentByStatePath('state');
    $city = $schema->getComponentByStatePath('city');

    expect($country)->toBeInstanceOf(Select::class)
        ->and($state)->toBeInstanceOf(Select::class)
        ->and($city)->toBeInstanceOf(Select::class)
        ->and($country->getOptions())->toBe(Helpers::getCountries());
});

it('prevents deleting locations that still have members', function (): void {
    expect(config('prevent-deletion.'.Location::class))->toBe(['members']);
});
