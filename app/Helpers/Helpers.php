<?php

namespace App\Helpers;

use App\Contracts\SequenceRepository;
use App\Contracts\SettingsRepository;
use App\Contracts\TenantContext;
use App\Filament\Forms\Components\PhoneField;
use App\Models\Plan;
use App\Services\JsonSettingsRepository;
use App\Support\AppConfig;
use App\Support\Billing\Currency;
use App\Support\Billing\Discounts;
use App\Support\Billing\TaxRate;
use App\Support\Data;
use App\Support\Dates\FiscalYear;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class Helpers
{
    public const PHOTO_DISK = 'public';

    public const PHOTO_DIRECTORY = 'images';

    /** @var int Max decoded photo size in bytes (5 MB). */
    public const PHOTO_MAX_BYTES = 5 * 1024 * 1024;

    private const DEFAULT_CURRENCY = 'INR';

    private const DEFAULT_EXPENSE_CATEGORIES = [
        'Rent',
        'Utilities',
        'Supplies',
        'Maintenance',
        'Marketing',
        'Equipment',
        'Payroll',
        'Travel',
        'Other',
    ];

    /**
     * @param  array<string, mixed>|null  $override
     */
    public static function setTestSettingsOverride(?array $override): void
    {
        /** @var mixed $repository */
        $repository = app(SettingsRepository::class);

        if ($repository instanceof JsonSettingsRepository) {
            $repository->setTestOverride($override);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function getSettings(): array
    {
        return app(SettingsRepository::class)->get();
    }

    public static function appTimezone(): string
    {
        return AppConfig::timezone();
    }

    /**
     * Get a list of all countries.
     *
     * @return array<string, string>
     */
    public static function getCountries(): array
    {
        try {
            /** @var class-string<Model> $model */
            $model = config('world.models.countries');

            $countries = $model::query()
                ->orderBy('name')
                ->pluck('name', 'name')
                ->map(fn (mixed $name): string => Data::string($name))
                ->all();

            if ($countries !== []) {
                return $countries;
            }
        } catch (Throwable) {
            // fall through to the bundled dataset
        }

        return collect(self::fallbackCountries())
            ->pluck('name', 'name')
            ->mapWithKeys(fn (mixed $name, mixed $key): array => [Data::string($key) => Data::string($name)])
            ->sortKeys()
            ->all();
    }

    /**
     * Get a list of all countries keyed by ISO2 code.
     *
     * @return array<string, string>
     */
    public static function getCountriesWithCodes(): array
    {
        try {
            /** @var class-string<Model> $model */
            $model = config('world.models.countries');

            $countries = $model::query()
                ->pluck('name', 'iso2')
                ->filter(fn (mixed $name, mixed $code): bool => Data::string($code) !== '' && Data::string($name) !== '')
                ->sort()
                ->all();

            if ($countries !== []) {
                return $countries;
            }
        } catch (Throwable) {
            // fall through to the bundled dataset
        }

        return collect(self::fallbackCountries())
            ->mapWithKeys(fn (mixed $country): array => [
                Data::string(data_get($country, 'iso2')) => Data::string(data_get($country, 'name')),
            ])
            ->filter(fn (mixed $name, mixed $code): bool => Data::string($code) !== '' && Data::string($name) !== '')
            ->sort()
            ->all();
    }

    /**
     * Get the phone code for a specific country name.
     */
    public static function getCountryPhoneCode(?string $countryName): ?string
    {
        if (blank($countryName)) {
            return null;
        }

        $phoneCode = self::countryPhoneCodeFromDatabase($countryName);

        if ($phoneCode === null) {
            $phoneCode = self::countryPhoneCodeFromDataset($countryName);
        }

        if ($phoneCode === null) {
            return null;
        }

        $phoneCode = ltrim($phoneCode, '+');

        return $phoneCode !== '' ? $phoneCode : null;
    }

    private static function countryPhoneCodeFromDatabase(string $countryName): ?string
    {
        try {
            /** @var class-string<Model> $model */
            $model = config('world.models.countries');

            return Data::nullableString($model::query()->where('name', $countryName)->value('phone_code'));
        } catch (Throwable) {
            return null;
        }
    }

    private static function countryPhoneCodeFromDataset(string $countryName): ?string
    {
        $country = collect(self::fallbackCountries())
            ->first(fn (mixed $country): bool => Data::string(data_get($country, 'name')) === $countryName);

        return Data::nullableString(data_get($country, 'phone_code'));
    }

    /**
     * Resolve the country name from the tenant location, falling back to
     * the global settings. Returns null when nothing is configured.
     */
    private static function resolveCountryName(): ?string
    {
        try {
            $location = app(TenantContext::class)->location();

            $countryName = $location?->country;

            if (blank($countryName)) {
                $settings = self::getSettings();
                $general = is_array($settings['general'] ?? null) ? $settings['general'] : [];
                $countryName = Data::nullableString($general['country'] ?? null);
            }

            return $countryName;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Get the phone placeholder with country code based on the current
     * location, falling back to local translation.
     */
    public static function getPhonePlaceholder(): string
    {
        $fallback = Data::string(__('app.placeholders.example_phone'));

        try {
            $phoneCode = self::getCountryPhoneCode(self::resolveCountryName());

            if (blank($phoneCode)) {
                return $fallback;
            }

            if (preg_match('/^\+\d+/', $fallback)) {
                return preg_replace('/^\+\d+/', '+'.$phoneCode, $fallback) ?? $fallback;
            }

            return '+'.$phoneCode.' '.$fallback;
        } catch (Throwable $e) {
            return $fallback;
        }
    }

    /**
     * The bare country-code prefix (e.g. "+54") for phone placeholders.
     * Falls back to the leading code from the translated example phone,
     * then to the full example phone when nothing is resolvable.
     */
    public static function getPhoneCountryCodePlaceholder(): string
    {
        $phoneCode = self::getCountryPhoneCode(self::resolveCountryName());

        if ($phoneCode !== null) {
            return '+'.$phoneCode;
        }

        $fallback = Data::string(__('app.placeholders.example_phone'));

        if (preg_match('/^\+\d+/', $fallback, $matches)) {
            return $matches[0];
        }

        return $fallback;
    }

    /**
     * A local-number example for phone inputs that have a separate dial-code
     * picker: the translated example phone with any leading "+<code>" prefix
     * stripped (e.g. "+54 555-123-4567" -> "555-123-4567").
     */
    public static function getPhoneLocalPlaceholder(): string
    {
        $example = Data::string(__('app.placeholders.example_phone'));

        $stripped = preg_replace('/^\+\d+\s*/', '', $example) ?? $example;

        return $stripped !== '' ? $stripped : $example;
    }

    /**
     * The full dial-code picker options: every country with a phone code,
     * sorted by name, each as ['code' => '+54', 'name' => 'Argentina'].
     * Reads the world tables when seeded, otherwise the bundled dataset.
     *
     * @return array<int, array{code: string, name: string}>
     */
    public static function getCountryDialOptions(): array
    {
        try {
            /** @var class-string<Model> $model */
            $model = config('world.models.countries');

            $rows = $model::query()
                ->whereNotNull('phone_code')
                ->where('phone_code', '!=', '')
                ->get(['name', 'phone_code']);

            if ($rows->isNotEmpty()) {
                return $rows
                    ->map(fn (Model $country): array => [
                        'code' => '+'.ltrim(Data::string($country->getAttribute('phone_code')), '+'),
                        'name' => Data::string($country->getAttribute('name')),
                    ])
                    ->filter(fn (array $option): bool => $option['code'] !== '+' && $option['name'] !== '')
                    ->sortBy('name')
                    ->values()
                    ->all();
            }
        } catch (Throwable) {
            // fall through to the bundled dataset
        }

        return collect(self::fallbackCountries())
            ->map(fn (array $country): array => [
                'code' => '+'.ltrim(Data::string(data_get($country, 'phone_code')), '+'),
                'name' => Data::string(data_get($country, 'name')),
            ])
            ->filter(fn (array $option): bool => $option['code'] !== '+' && $option['name'] !== '')
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * Return a Filament Group containing a dial-code Select and a phone
     * TextInput. The Select is non-dehydrated (not saved to the DB); the
     * caller combines them in mutateFormDataBeforeCreate / BeforeSave.
     *
     * @param  string  $fieldName  The model attribute (e.g. 'contact').
     */
    public static function phoneField(string $fieldName, bool $required = false): PhoneField
    {
        return PhoneField::make($fieldName)
            ->label(__('app.fields.'.$fieldName))
            ->required($required);
    }

    /**
     * Combine a dial_code value with a raw phone number, returning the
     * full prefixed, normalized string (e.g. '+54261599999'). Strips any
     * existing +code prefix from the raw number first.
     */
    public static function combinePhoneField(string $dialCode, string $phone): string
    {
        $raw = preg_replace('/^\+\d+\s*/', '', $phone) ?? $phone;

        $combined = $dialCode.' '.ltrim($raw, ' +');

        return self::normalizePhone($combined) ?? $combined;
    }

    /**
     * Parse a stored phone number (e.g. '+54261599999') into its dial-code
     * prefix and local number, returning [dialCode, localNumber].
     * Always strips the prefix so the local input never shows the area code,
     * even when the code is not in the known dial list. Falls back to the
     * default code when no prefix is found.
     *
     * @return array{0: string, 1: string}
     */
    public static function parsePhoneField(?string $stored): array
    {
        if (blank($stored)) {
            return [self::getPhoneCountryCodePlaceholder(), ''];
        }

        $raw = trim((string) $stored);

        // Only prefixed numbers have a dial code to extract. An unprefixed
        // value (legacy data) is local-only, so its leading digits must not
        // be mistaken for a country code (e.g. "555..." matching +55).
        if (! str_starts_with($raw, '+')) {
            return [self::getPhoneCountryCodePlaceholder(), $raw];
        }

        $value = ltrim($raw, '+');

        // Try matching against known dial codes (longest first to avoid
        // partial matches like +1 before +1212).
        $codes = collect(self::getCountryDialOptions())
            ->map(fn (array $o): string => ltrim($o['code'], '+'))
            ->sortByDesc(fn (string $c): int => strlen($c))
            ->values()
            ->all();

        foreach ($codes as $code) {
            if (str_starts_with($value, $code)) {
                $local = ltrim(substr($value, strlen($code)));

                return ['+'.$code, $local];
            }
        }

        // No known code matched: the number starts with '+', so strip
        // whatever leading digits it has rather than showing the code in the
        // local input.
        if (preg_match('/^\+\d{1,3}/', $raw, $matches)) {
            $local = ltrim(substr($value, strlen(ltrim($matches[0], '+'))));

            return [$matches[0], $local];
        }

        return [self::getPhoneCountryCodePlaceholder(), $value];
    }

    /**
     * Normalize a phone number for storage and lookups: strip formatting
     * (spaces, dashes, parentheses, dots) and prefix the country code when
     * the number has no prefix of its own. Returns null for blank input.
     */
    public static function normalizePhone(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $cleaned = preg_replace('/[\s\-\(\)\.]/', '', trim($value)) ?? trim($value);

        if ($cleaned === '') {
            return null;
        }

        if (str_starts_with($cleaned, '+')) {
            return $cleaned;
        }

        $phoneCode = self::getCountryPhoneCode(self::resolveCountryName());

        if (blank($phoneCode)) {
            return $cleaned;
        }

        return '+'.$phoneCode.$cleaned;
    }

    /**
     * Build a public URL for a stored photo path, or pass through
     * absolute/data URLs untouched.
     */
    public static function photoUrl(?string $photo): ?string
    {
        if (blank($photo)) {
            return null;
        }

        if (filter_var($photo, FILTER_VALIDATE_URL) !== false || str_starts_with($photo, 'data:')) {
            return $photo;
        }

        return Storage::disk('public')->url($photo);
    }

    /**
     * Decode a base64 image data URL and store it on the public disk.
     *
     * @throws InvalidArgumentException When the data URL is not a supported image or is too large.
     */
    public static function storePhotoDataUrl(string $dataUrl): string
    {
        if (! preg_match('/^data:image\/(jpeg|png|webp);base64,/', $dataUrl, $matches)) {
            throw new InvalidArgumentException(__('app.reception.verify_photo_invalid'));
        }

        $encoded = substr($dataUrl, strpos($dataUrl, ',') + 1);
        $decoded = base64_decode($encoded, true);

        if ($decoded === false || $decoded === '') {
            throw new InvalidArgumentException(__('app.reception.verify_photo_invalid'));
        }

        if (strlen($decoded) > self::PHOTO_MAX_BYTES) {
            throw new InvalidArgumentException(__('app.reception.verify_photo_invalid'));
        }

        $extension = $matches[1] === 'jpeg' ? 'jpg' : $matches[1];
        $path = self::PHOTO_DIRECTORY.'/member-'.Str::uuid().'.'.$extension;

        Storage::disk(self::PHOTO_DISK)->put($path, $decoded);

        return $path;
    }

    /**
     * Get a list of states for a specific country.
     *
     * @param  string|null  $countryName  The name of the country
     * @return array<string, string>
     */
    public static function getStates(?string $countryName): array
    {
        if (blank($countryName)) {
            return [];
        }

        try {
            /** @var class-string<Model> $countryModel */
            $countryModel = config('world.models.countries');
            /** @var class-string<Model> $stateModel */
            $stateModel = config('world.models.states');

            $countryId = $countryModel::query()->where('name', $countryName)->value('id');

            if ($countryId !== null) {
                $states = $stateModel::query()
                    ->where('country_id', $countryId)
                    ->orderBy('name')
                    ->pluck('name', 'name')
                    ->map(fn (mixed $name): string => Data::string($name))
                    ->all();

                if ($states !== []) {
                    return $states;
                }
            }
        } catch (Throwable) {
            // fall through to the bundled dataset
        }

        $key = mb_strtolower(trim($countryName));

        return self::fallbackStates()[$key] ?? [];
    }

    /**
     * Get a list of cities for a specific state.
     *
     * @param  string|null  $stateName  The name of the state
     * @param  string|null  $countryName  The name of the country to disambiguate repeated state names
     * @return array<string, string>
     */
    public static function getCities(?string $stateName, ?string $countryName = null): array
    {
        if (blank($stateName)) {
            return [];
        }

        try {
            /** @var class-string<Model> $stateModel */
            $stateModel = config('world.models.states');
            /** @var class-string<Model> $cityModel */
            $cityModel = config('world.models.cities');

            $stateQuery = $stateModel::query()->where('name', $stateName);

            if (! blank($countryName)) {
                /** @var class-string<Model> $countryModel */
                $countryModel = config('world.models.countries');
                $countryId = $countryModel::query()->where('name', $countryName)->value('id');
                if ($countryId !== null) {
                    $stateQuery->where('country_id', $countryId);
                }
            }

            $stateId = $stateQuery->value('id');

            if ($stateId !== null) {
                $cities = $cityModel::query()
                    ->where('state_id', $stateId)
                    ->orderBy('name')
                    ->pluck('name', 'name')
                    ->map(fn (mixed $name): string => Data::string($name))
                    ->all();

                if ($cities !== []) {
                    return $cities;
                }
            }
        } catch (Throwable) {
            // fall through to the bundled dataset
        }

        $key = mb_strtolower(trim($stateName));
        $cities = self::fallbackCities()[$key] ?? null;

        if (! is_array($cities)) {
            return [];
        }

        if (blank($countryName)) {
            $flattened = [];
            foreach ($cities as $countryCities) {
                foreach ($countryCities as $name => $label) {
                    $flattened[$name] = $label;
                }
            }

            return $flattened;
        }

        return $cities[mb_strtolower(trim($countryName))] ?? [];
    }

    /**
     * Get a list of currencies.
     *
     * @return array<string, string>
     */
    public static function getCurrencies(): array
    {
        try {
            /** @var class-string<Model> $model */
            $model = config('world.models.currencies');

            $currencies = $model::query()
                ->orderBy('name')
                ->pluck('name', 'code')
                ->map(fn (mixed $name): string => Data::string($name))
                ->all();

            if ($currencies !== []) {
                return $currencies;
            }
        } catch (Throwable) {
            // fall through
        }

        return self::fallbackCurrencies();
    }

    /**
     * Get the currency code from the current location, falling back to settings.
     */
    public static function getCurrencyCode(): string
    {
        $location = app(TenantContext::class)->location();

        if ($location !== null && filled($location->currency)) {
            return Data::string($location->currency, self::DEFAULT_CURRENCY);
        }

        return Currency::codeFromSettings(self::getSettings(), self::DEFAULT_CURRENCY);
    }

    /**
     * Get the number of days before a subscription is considered expiring.
     */
    public static function getSubscriptionExpiringDays(): int
    {
        $settings = self::getSettings();
        $subscriptions = is_array($settings['subscriptions'] ?? null) ? $settings['subscriptions'] : [];
        $days = $subscriptions['expiring_days'] ?? 7;

        if (! is_numeric($days)) {
            return 7;
        }

        return max(1, (int) $days);
    }

    /**
     * Get expense categories from settings (fallback to defaults).
     *
     * @return array<int, string>
     */
    public static function getExpenseCategories(): array
    {
        $settings = self::getSettings();
        $expenses = is_array($settings['expenses'] ?? null) ? $settings['expenses'] : [];
        $categories = $expenses['categories'] ?? null;

        if (! is_array($categories) || empty($categories)) {
            return self::DEFAULT_EXPENSE_CATEGORIES;
        }

        $normalized = [];
        foreach ($categories as $category) {
            $category = trim(Data::string($category));
            if ($category === '') {
                continue;
            }
            $normalized[$category] = $category;
        }

        return array_values($normalized);
    }

    /**
     * Get expense category options for selects.
     *
     * @return array<string, string>
     */
    public static function getExpenseCategoryOptions(): array
    {
        $options = [];
        foreach (self::getExpenseCategories() as $category) {
            $key = Str::slug($category);

            if ($key === '') {
                continue;
            }

            $translationKey = "app.expense_categories.{$key}";
            $options[$key] = Lang::has($translationKey) ? __($translationKey) : $category;
        }

        return $options;
    }

    public static function getExpenseCategoryLabel(?string $key): ?string
    {
        if (blank($key)) {
            return null;
        }

        return self::getExpenseCategoryOptions()[$key] ?? $key;
    }

    /**
     * Get the discounts from settings.
     */
    /**
     * @return array<string, string>
     */
    public static function getDiscounts(): array
    {
        return Discounts::optionsFromSettings(self::getSettings());
    }

    /**
     * Get the discount amount.
     */
    public static function getDiscountAmount(?float $discount, ?float $fee): float
    {
        return Discounts::amount($discount, $fee);
    }

    /**
     * Get the tax rate from settings.
     */
    public static function getTaxRate(): float
    {
        return TaxRate::fromSettings(self::getSettings());
    }

    /**
     * Format the currency value.
     */
    public static function formatCurrency(?float $value, ?string $currency = null): string
    {
        $currency = $currency ?? self::getCurrencyCode();

        return Currency::format($value, $currency);
    }

    /**
     * Get the currency symbol.
     *
     * @return string The currency symbol.
     */
    public static function getCurrencySymbol(): string
    {
        return Currency::symbol(self::getCurrencyCode());
    }

    /**
     * Parse a date string or return now().
     *
     * @param  string|null  $dateString  The date string to parse.
     * @return Carbon Parsed Carbon instance, or now() if input is null or empty.
     */
    public static function parseDate(?string $dateString): Carbon
    {
        return $dateString ? Carbon::parse($dateString) : Carbon::now();
    }

    /**
     * Determine fiscal year start and end dates for the given date.
     *
     * The fiscal year is configured per location, falling back to the
     * legacy settings template.
     *
     * @param  Carbon  $date  The date to calculate the fiscal period for.
     * @return array{0: Carbon, 1: Carbon} Array with [start, end] Carbon instances of the fiscal year.
     */
    public static function getFiscalSpan(Carbon $date): array
    {
        $generalSettings = self::getSettings()['general'] ?? [];

        if (! is_array($generalSettings)) {
            $generalSettings = [];
        }

        $location = app(TenantContext::class)->location();

        if ($location !== null) {
            if ($location->financial_year_start !== null) {
                $generalSettings['financial_year_start'] = $location->financial_year_start->toDateString();
            }

            if ($location->financial_year_end !== null) {
                $generalSettings['financial_year_end'] = $location->financial_year_end->toDateString();
            }
        }

        return FiscalYear::spanForDate($date, $generalSettings);
    }

    /**
     * Generate the next sequential identifier for a given type and model.
     *
     * @param  string  $type  The type identifier used to fetch the corresponding settings.
     * @param  class-string  $modelClass  The fully qualified class name of the Eloquent model to query.
     * @param  string|null  $dateString  A date string used to determine the financial year span.
     * @param  string|null  $modalColumn  The model column to search for the last value (e.g. 'number' or 'code').
     * @return string The newly generated identifier, prefixed and suffixed as configured.
     */
    public static function generateLastNumber(string $type, string $modelClass, ?string $dateString = null, ?string $modalColumn = 'number'): string
    {
        return app(SequenceRepository::class)->generate(
            $type,
            $modelClass,
            $dateString,
            $modalColumn,
        );
    }

    /**
     * Persist the last number for a given type if within the current fiscal year.
     *
     * @param  string  $type  The type of setting to update.
     * @param  string  $newNumber  The new number to set as the last number.
     * @param  string|null  $date  The date to check against the financial year.
     */
    public static function updateLastNumber(string $type, string $newNumber, ?string $date = null): void
    {
        app(SequenceRepository::class)->update($type, $newNumber, $date);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function fallbackCountries(): array
    {
        $path = base_path('vendor/nnjeim/world/resources/json/countries.json');

        if (! is_file($path)) {
            return [];
        }

        /** @var mixed $countries */
        $countries = json_decode((string) file_get_contents($path), true);

        if (! is_array($countries)) {
            return [];
        }

        $filteredCountries = [];

        foreach ($countries as $country) {
            if (is_array($country)) {
                $filteredCountries[] = Data::map($country);
            }
        }

        return $filteredCountries;
    }

    /**
     * @return array<string, string>
     */
    private static function fallbackCurrencies(): array
    {
        $path = base_path('vendor/nnjeim/world/resources/json/currencies.json');

        if (! is_file($path)) {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            return [];
        }

        $currencies = [];

        foreach ($decoded as $code => $currency) {
            if (! is_array($currency)) {
                continue;
            }

            $name = Data::string(data_get($currency, 'name'));
            $code = Data::string($code);

            if ($name !== '' && $code !== '') {
                $currencies[$code] = $name;
            }
        }

        asort($currencies);

        return $currencies;
    }

    /**
     * The compacted world dataset (states/cities) used when the world tables
     * have not been seeded, mirroring the countries fallback. Decoded once
     * and cached to keep lookups cheap.
     *
     * @return array{states: array<string, array<string, string>>, cities: array<string, array<string, array<string, string>>>}
     */
    private static function fallbackStates(): array
    {
        return Cache::rememberForever('gymie.world_states', function (): array {
            $base = base_path('vendor/nnjeim/world/resources/json');
            $states = [];

            $statesPath = $base.'/states.json';
            if (is_file($statesPath)) {
                /** @var mixed $decoded */
                $decoded = json_decode((string) file_get_contents($statesPath), true);

                if (is_array($decoded)) {
                    foreach ($decoded as $state) {
                        if (! is_array($state)) {
                            continue;
                        }

                        $country = Data::string(data_get($state, 'country_name'));
                        $name = Data::string(data_get($state, 'name'));

                        if ($country !== '' && $name !== '') {
                            $states[mb_strtolower($country)][$name] = $name;
                        }
                    }
                }
            }

            return $states;
        });
    }

    private static function fallbackCities(): array
    {
        return Cache::store('file')->rememberForever('gymie.world_cities', function (): array {
            $base = base_path('vendor/nnjeim/world/resources/json');
            $cities = [];

            $previousLimit = ini_set('memory_limit', '2048M');

            try {
                $citiesPath = $base.'/cities.json';
                if (is_file($citiesPath)) {
                    /** @var mixed $decoded */
                    $decoded = json_decode((string) file_get_contents($citiesPath), true);

                    if (is_array($decoded)) {
                        foreach ($decoded as $city) {
                            if (! is_array($city)) {
                                continue;
                            }

                            $state = Data::string(data_get($city, 'state_name'));
                            $country = Data::string(data_get($city, 'country_name'));
                            $name = Data::string(data_get($city, 'name'));

                            if ($state !== '' && $country !== '' && $name !== '') {
                                $cities[mb_strtolower($state)][mb_strtolower($country)][$name] = $name;
                            }
                        }
                    }
                }

                return $cities;
            } finally {
                if ($previousLimit !== false) {
                    @ini_set('memory_limit', $previousLimit);
                }
            }
        });
    }

    /**
     * Given a subscription start date and a plan ID, return the Y-m-d end date
     * (or empty string if no valid plan/days).
     */
    public static function calculateSubscriptionEndDate(?string $startDate, ?int $planId): string
    {
        if (! $startDate || ! $planId) {
            return '';
        }

        $plan = Plan::find($planId);
        if (! $plan || ! $plan->days) {
            return '';
        }

        return Carbon::parse($startDate)
            ->addDays((int) $plan->days)
            ->toDateString();
    }
}
