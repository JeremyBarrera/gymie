<?php

namespace App\Models\Concerns;

use App\Contracts\TenantContext;
use App\Models\User;
use App\Support\Locations\LocationAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

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
