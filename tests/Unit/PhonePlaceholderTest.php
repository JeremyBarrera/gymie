<?php

use App\Helpers\Helpers;
use Filament\Forms\Components\TextInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nnjeim\World\Models\Country;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Country::query()->create([
        'iso2' => 'IN',
        'iso3' => 'IND',
        'name' => 'India',
        'phone_code' => '91',
        'region' => 'Asia',
        'subregion' => 'Southern Asia',
        'status' => 1,
    ]);
});

it('returns fallback phone placeholder when no country is configured', function (): void {
    Helpers::setTestSettingsOverride([]);

    expect(Helpers::getPhonePlaceholder())->toBe(__('app.placeholders.example_phone'));
});

it('returns phone placeholder with country code when country is configured in settings', function (): void {
    Helpers::setTestSettingsOverride([
        'general' => [
            'country' => 'India',
        ],
    ]);

    expect(Helpers::getPhonePlaceholder())->toContain('+91');
});

it('returns the bare country code for the placeholder when a country is configured', function (): void {
    Helpers::setTestSettingsOverride([
        'general' => [
            'country' => 'India',
        ],
    ]);

    expect(Helpers::getPhoneCountryCodePlaceholder())->toBe('+91');
});

it('falls back to the translated code when no country is configured', function (): void {
    Helpers::setTestSettingsOverride([]);

    expect(Helpers::getPhoneCountryCodePlaceholder())->toMatch('/^\+\d+$/');
});

it('automatically sets the phone placeholder on any telephone TextInput component', function (): void {
    Helpers::setTestSettingsOverride([
        'general' => [
            'country' => 'India',
        ],
    ]);

    $input = TextInput::make('contact')->tel();

    expect($input->getPlaceholder())->toBe(Helpers::getPhonePlaceholder())
        ->and($input->getPlaceholder())->toContain('+91');
});

it('does not set the phone placeholder on non-telephone TextInput components', function (): void {
    $input = TextInput::make('name');

    expect($input->getPlaceholder())->toBeNull();
});

it('normalizes a formatted number and strips separators', function (): void {
    expect(Helpers::normalizePhone('(+91) 98765-43210'))->toBe('+919876543210')
        ->and(Helpers::normalizePhone('98765.43210'))->toBe('+919876543210');
});

it('prefixes the country code when the number has none', function (): void {
    Helpers::setTestSettingsOverride([
        'general' => [
            'country' => 'India',
        ],
    ]);

    expect(Helpers::normalizePhone('98765 43210'))->toBe('+919876543210');
});

it('keeps an already-prefixed number unchanged', function (): void {
    expect(Helpers::normalizePhone('+919876543210'))->toBe('+919876543210');
});

it('returns null for blank phone input', function (): void {
    expect(Helpers::normalizePhone(null))->toBeNull()
        ->and(Helpers::normalizePhone(''))->toBeNull()
        ->and(Helpers::normalizePhone('   '))->toBeNull();
});

it('cleans formatting even when no country is configured', function (): void {
    Helpers::setTestSettingsOverride([]);

    expect(Helpers::normalizePhone('(555) 123-4567'))->toBe('5551234567');
});

it('strips the country code from the translated example for local placeholders', function (): void {
    expect(Helpers::getPhoneLocalPlaceholder())->not->toMatch('/^\+/')
        ->and(Helpers::getPhoneLocalPlaceholder())->not->toBeEmpty();
});

it('returns an empty-friendly placeholder when the example is only a code', function (): void {
    expect(Helpers::getPhoneLocalPlaceholder())->not->toBe('+');
});

it('uses the full bundled dataset when the world tables are empty', function (): void {
    Country::query()->delete();

    $options = Helpers::getCountryDialOptions();

    expect($options)->not->toBeEmpty()
        ->and(count($options))->toBeGreaterThan(200)
        ->and($options[0])->toHaveKeys(['code', 'name']);
});

it('lists every seeded country with a phone code for the dial picker', function (): void {
    Country::query()->create([
        'iso2' => 'AR',
        'iso3' => 'ARG',
        'name' => 'Argentina',
        'phone_code' => '54',
        'region' => 'Americas',
        'subregion' => 'South America',
        'status' => 1,
    ]);
    Country::query()->create([
        'iso2' => 'DE',
        'iso3' => 'DEU',
        'name' => 'Germany',
        'phone_code' => '49',
        'region' => 'Europe',
        'subregion' => 'Western Europe',
        'status' => 1,
    ]);

    $options = Helpers::getCountryDialOptions();

    expect(count($options))->toBe(3)
        ->and($options[0])->toBe(['code' => '+54', 'name' => 'Argentina'])
        ->and($options[1])->toBe(['code' => '+49', 'name' => 'Germany'])
        ->and($options[2])->toBe(['code' => '+91', 'name' => 'India']);
});

it('sorts dial picker options by country name', function (): void {
    $options = Helpers::getCountryDialOptions();
    $names = array_column($options, 'name');
    $sorted = $names;
    sort($sorted);

    expect($names)->toBe($sorted);
});

it('includes the configured country in the dial picker options', function (): void {
    $options = Helpers::getCountryDialOptions();

    expect(collect($options)->firstWhere('name', 'India'))->toBe(['code' => '+91', 'name' => 'India']);
});

it('formats every dial code with a leading plus', function (): void {
    $options = Helpers::getCountryDialOptions();

    foreach ($options as $option) {
        expect($option['code'])->toMatch('/^\+\d+$/');
    }
});

it('combines a dial code with a raw phone number', function (): void {
    expect(Helpers::combinePhoneField('+54', '261 599 999'))->toBe('+54261599999');
});

it('strips an existing prefix when combining', function (): void {
    expect(Helpers::combinePhoneField('+91', '+91 98765 43210'))->toBe('+919876543210');
});

it('parses a stored phone number into dial code and local number', function (): void {
    [$code, $phone] = Helpers::parsePhoneField('+919876543210');

    expect($code)->toBe('+91')
        ->and($phone)->toBe('9876543210');
});

it('parses a stored number with spaces', function (): void {
    [$code, $phone] = Helpers::parsePhoneField('+91 98765 43210');

    expect($code)->toBe('+91')
        ->and($phone)->toBe('98765 43210');
});

it('strips an unknown dial code from the local number', function (): void {
    [$code, $phone] = Helpers::parsePhoneField('+973261599999');

    expect($code)->toBe('+973')
        ->and($phone)->toBe('261599999');
});

it('falls back to the default code when no prefix is found', function (): void {
    Helpers::setTestSettingsOverride([]);

    [$code, $phone] = Helpers::parsePhoneField('5551234567');

    expect($code)->toMatch('/^\+\d+$/')
        ->and($phone)->toBe('5551234567');
});

it('returns empty phone for blank stored values', function (): void {
    [$code, $phone] = Helpers::parsePhoneField(null);

    expect($phone)->toBe('')
        ->and($code)->toMatch('/^\+\d+$/');
});
