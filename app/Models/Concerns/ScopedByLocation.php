<?php

namespace App\Models\Concerns;

use App\Contracts\TenantContext;
use App\Models\User;
use App\Support\Locations\LocationAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * Restricts a model to the current location (tenant).
 *
 * - Every query is filtered to the account's accessible locations when the
 *   account is authenticated (via `user_locations`).
 * - Rows with a `null` location belong to **every** location ("all locations"
 *   jurisdiction — used by all-locations plans and the members that follow
 *   them), so they are always included.
 * - Unauthenticated public flows are filtered to the pinned location resolved
 *   from the location token / queue entry.
 * - The `owner` role bypasses the scope entirely (sees every location).
 * - New records get the current location's id filled in automatically.
 * - When no location can be resolved the scope is inert, which keeps
 *   single-tenant installs working as before.
 */
trait ScopedByLocation
{
    public static function bootScopedByLocation(): void
    {
        static::addGlobalScope('location', function (Builder $builder): void {
            $locationIds = self::currentLocationIds();

            if ($locationIds === null) {
                return;
            }

            $table = (new static)->getTable();

            if (self::tableHasLocationColumn($table)) {
                $builder->where(function (Builder $query) use ($table, $locationIds): void {
                    $query->whereIn($table.'.location_id', $locationIds)
                        ->orWhereNull($table.'.location_id');
                });

                return;
            }

            // Models without a location_id column (users) are scoped through
            // their `user_locations` pivot.
            $builder->whereHas('locations', function (Builder $query) use ($locationIds): void {
                $query->whereIn('locations.id', $locationIds);
            });
        });

        static::creating(function (Model $model): void {
            if ($model->getAttribute('location_id') !== null) {
                return;
            }

            if (! self::tableHasLocationColumn($model->getTable())) {
                return;
            }

            $locationId = self::defaultLocationId();

            if ($locationId !== null) {
                $model->setAttribute('location_id', $locationId);
            }
        });
    }

    /**
     * The location ids to scope queries with, or null when the scope is inert.
     *
     * @return list<int>|null
     */
    protected static function currentLocationIds(): ?array
    {
        $user = Auth::user();

        if ($user instanceof User) {
            if ($user->isOwner()) {
                return null;
            }

            return LocationAccess::accessibleLocationIds($user);
        }

        $locationId = app(TenantContext::class)->locationId();

        return $locationId !== null ? [(int) $locationId] : null;
    }

    /**
     * The location id to fill on new records, or null when none is resolved.
     */
    protected static function defaultLocationId(): ?int
    {
        $user = Auth::user();

        if ($user instanceof User) {
            if ($user->isOwner()) {
                return null;
            }

            return LocationAccess::firstAccessibleLocationId($user);
        }

        $locationId = app(TenantContext::class)->locationId();

        return $locationId !== null ? (int) $locationId : null;
    }

    private static function tableHasLocationColumn(string $table): bool
    {
        return Schema::hasColumn($table, 'location_id');
    }
}
