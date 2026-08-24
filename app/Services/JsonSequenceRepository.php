<?php

namespace App\Services;

use App\Contracts\SequenceRepository;
use App\Contracts\SettingsRepository;
use App\Helpers\Helpers;
use App\Support\Data;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * JSON-backed sequence generator (OSS default).
 *
 * Reads prefix / last_number from SettingsRepository and also inspects the DB
 * to avoid collisions within the current fiscal span.
 */
class JsonSequenceRepository implements SequenceRepository
{
    public function __construct(
        protected SettingsRepository $settingsRepository,
    ) {}

    /**
     * Generate the next number for a given entity type.
     *
     * The database is the single source of truth: the highest numeric
     * suffix within the fiscal span defines the sequence, so numbering
     * always starts at 1 on an empty span and can never be poisoned by a
     * stale settings value.
     *
     * @param  class-string  $modelClass
     */
    public function generate(
        string $type,
        string $modelClass,
        ?string $dateString = null,
        ?string $modelColumn = 'number',
    ): string {
        $date = Helpers::parseDate($dateString);
        [$start, $end] = Helpers::getFiscalSpan($date);
        $settings = $this->settingsRepository->get();

        /** @var Model $model */
        $model = new $modelClass;
        $table = $model->getTable();

        $dateColumn = Schema::hasColumn($table, 'date')
            ? 'date'
            : 'created_at';

        $rawPrefix = data_get($settings, "{$type}.prefix", '');
        $prefix = trim(Data::string($rawPrefix), '-');
        $prefix = filled($prefix) ? $prefix : 'GY';
        $separator = $prefix !== '' ? '-' : '';
        $match = $prefix.$separator;

        $lastFromDb = $modelClass::query()
            ->withoutGlobalScopes()
            ->whereBetween($dateColumn, [$start->toDateString(), $end->toDateString()])
            ->pluck($modelColumn ?? 'number')
            ->map(
                fn ($raw) => Str::of(Data::string($raw))
                    ->whenStartsWith($match, fn ($s) => $s->after($match))
                    ->__toString()
            )
            ->map(fn ($v) => is_numeric($v) ? (int) $v : 0)
            ->max() ?: 0;

        return str($prefix)
            ->when($separator !== '', fn ($s) => $s->append($separator))
            ->append((string) ($lastFromDb + 1))
            ->__toString();
    }
}
