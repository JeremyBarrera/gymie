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

    

    public static function setTestSettingsOverride(?array $override): void
    {
        
        $repository = app(SettingsRepository::class);

        if ($repository instanceof JsonSettingsRepository) {
            $repository->setTestOverride($override);
        }
    }

    

    public static function getSettings(): array
    {
        return app(SettingsRepository::class)->get();
    }

    public static function appTimezone(): string
    {
        return AppConfig::timezone();
    }

    

    public static function getCountries(): array
    {
        try {
            
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
            
        }

        return collect(self::fallbackCountries())
            ->pluck('name', 'name')
            ->mapWithKeys(fn (mixed $name, mixed $key): array => [Data::string($key) => Data::string($name)])
            ->sortKeys()
            ->all();
    }

    

    public static function getCountriesWithCodes(): array
    {
        try {
            
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
            
        }

        return collect(self::fallbackCountries())
            ->mapWithKeys(fn (mixed $country): array => [
                Data::string(data_get($country, 'iso2')) => Data::string(data_get($country, 'name')),
            ])
            ->filter(fn (mixed $name, mixed $code): bool => Data::string($code) !== '' && Data::string($name) !== '')
            ->sort()
            ->all();
    }

    

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

    

    public static function getPhoneLocalPlaceholder(): string
    {
        $example = Data::string(__('app.placeholders.example_phone'));

        $stripped = preg_replace('/^\+\d+\s*/', '', $example) ?? $example;

        return $stripped !== '' ? $stripped : $example;
    }

    

    public static function getCountryDialOptions(): array
    {
        try {
            
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

    

    public static function phoneField(string $fieldName, bool $required = false): PhoneField
    {
        return PhoneField::make($fieldName)
            ->label(__('app.fields.'.$fieldName))
            ->required($required);
    }

    

    public static function combinePhoneField(string $dialCode, string $phone): string
    {
        $raw = preg_replace('/^\+\d+\s*/', '', $phone) ?? $phone;

        $combined = $dialCode.' '.ltrim($raw, ' +');

        return self::normalizePhone($combined) ?? $combined;
    }

    

    public static function parsePhoneField(?string $stored): array
    {
        if (blank($stored)) {
            return [self::getPhoneCountryCodePlaceholder(), ''];
        }

        $raw = trim((string) $stored);

        
        
        
        if (! str_starts_with($raw, '+')) {
            return [self::getPhoneCountryCodePlaceholder(), $raw];
        }

        $value = ltrim($raw, '+');

        
        
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

        
        
        
        if (preg_match('/^\+\d{1,3}/', $raw, $matches)) {
            $local = ltrim(substr($value, strlen(ltrim($matches[0], '+'))));

            return [$matches[0], $local];
        }

        return [self::getPhoneCountryCodePlaceholder(), $value];
    }

    

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

    

    public static function deleteStoredPhoto(?string $path): void
    {
        if (blank($path) || $path === '0') {
            return;
        }

        if (filter_var($path, FILTER_VALIDATE_URL) !== false || str_starts_with($path, 'data:')) {
            return;
        }

        Storage::disk(self::PHOTO_DISK)->delete($path);
    }

    

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

    

    public static function getStates(?string $countryName): array
    {
        if (blank($countryName)) {
            return [];
        }

        try {
            
            $countryModel = config('world.models.countries');
            
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
            
        }

        $key = mb_strtolower(trim($countryName));

        return self::fallbackStates()[$key] ?? [];
    }

    

    public static function getCities(?string $stateName, ?string $countryName = null): array
    {
        if (blank($stateName)) {
            return [];
        }

        try {
            
            $stateModel = config('world.models.states');
            
            $cityModel = config('world.models.cities');

            $stateQuery = $stateModel::query()->where('name', $stateName);

            if (! blank($countryName)) {
                
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

    

    public static function getCurrencies(): array
    {
        try {
            
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
            
        }

        return self::fallbackCurrencies();
    }

    

    public static function getCurrencyCode(): string
    {
        $location = app(TenantContext::class)->location();

        if ($location !== null && filled($location->currency)) {
            return Data::string($location->currency, self::DEFAULT_CURRENCY);
        }

        return Currency::codeFromSettings(self::getSettings(), self::DEFAULT_CURRENCY);
    }

    

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

    

    

    public static function getDiscounts(): array
    {
        $discounts = Discounts::optionsFromSettings(self::getSettings());

        return ['0' => __('app.options.no_discount')] + $discounts;
    }

    

    public static function getDiscountAmount(?float $discount, ?float $fee): float
    {
        return Discounts::amount($discount, $fee);
    }

    

    public static function getTaxRate(): float
    {
        return TaxRate::fromSettings(self::getSettings());
    }

    

    public static function formatCurrency(?float $value, ?string $currency = null): string
    {
        $currency = $currency ?? self::getCurrencyCode();

        return Currency::format($value, $currency);
    }

    

    public static function getCurrencySymbol(): string
    {
        return Currency::symbol(self::getCurrencyCode());
    }

    

    public static function parseDate(?string $dateString): Carbon
    {
        return $dateString ? Carbon::parse($dateString) : Carbon::now();
    }

    

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

    

    public static function generateLastNumber(string $type, string $modelClass, ?string $dateString = null, ?string $modalColumn = 'number'): string
    {
        return app(SequenceRepository::class)->generate(
            $type,
            $modelClass,
            $dateString,
            $modalColumn,
        );
    }

    

    private static function fallbackCountries(): array
    {
        $path = base_path('vendor/nnjeim/world/resources/json/countries.json');

        if (! is_file($path)) {
            return [];
        }

        
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

    

    private static function fallbackCurrencies(): array
    {
        $path = base_path('vendor/nnjeim/world/resources/json/currencies.json');

        if (! is_file($path)) {
            return [];
        }

        
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

    

    private static function fallbackStates(): array
    {
        return Cache::rememberForever('gymie.world_states', function (): array {
            $base = base_path('vendor/nnjeim/world/resources/json');
            $states = [];

            $statesPath = $base.'/states.json';
            if (is_file($statesPath)) {
                
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

    

    public static function calculateSubscriptionEndDate(?string $startDate, ?int $planId, int $quantity = 1): string
    {
        if (! $startDate || ! $planId) {
            return '';
        }

        $plan = Plan::find($planId);
        if (! $plan || ! $plan->days) {
            return '';
        }

        $quantity = max(1, $quantity);

        return Carbon::parse($startDate)
            ->addDays((int) $plan->days * $quantity)
            ->toDateString();
    }
}
