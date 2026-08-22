<?php

namespace App\Services;

use App\Contracts\TenantContext;
use App\Models\Location;
use App\Models\User;
use App\Support\Locations\LocationAccess;
use Illuminate\Support\Facades\Auth;

/**
 * Tenant-aware TenantContext implementation.
 *
 * The current location is pinned per request by the `SetCurrentLocation`
 * middleware on public member-facing routes (from the location token or the
 * queue entry). Authenticated requests resolve the first location the account
 * can access, and the `owner` role resolves nothing so its queries stay
 * unscoped. When nothing resolves a location, the default location (the first
 * row) is used so single-tenant installs behave exactly as before.
 */
class LocationTenantContext implements TenantContext
{
    private static ?int $pinnedLocationId = null;

    // Legacy cache fields kept for back-compat with unit tests that reset them
    // via reflection; resolution is no longer cached (see defaultLocationId()).
    private static bool $resolvedDefaultLocationId = false;

    private static ?int $defaultLocationId = null;

    /**
     * Pin the current location id for the remainder of the request/process.
     */
    public static function setLocationId(?int $locationId): void
    {
        self::$pinnedLocationId = $locationId;
    }

    /**
     * @return int|null The current Location id, or null when running single-tenant.
     */
    public function locationId(): ?int
    {
        if (self::$pinnedLocationId !== null) {
            return self::$pinnedLocationId;
        }

        $user = Auth::user();

        if ($user instanceof User) {
            if ($user->isOwner()) {
                return null;
            }

            $first = LocationAccess::firstAccessibleLocationId($user);

            if ($first !== null) {
                return $first;
            }
        }

        return self::defaultLocationId();
    }

    /**
     * The current Location model instance, or null when no location is resolved.
     */
    public function location(): ?Location
    {
        $locationId = $this->locationId();

        return $locationId !== null ? Location::query()->find($locationId) : null;
    }

    /**
     * The first location (the default tenant) used for every flow that does
     * not resolve a location explicitly.
     */
    public function defaultLocation(): ?Location
    {
        $locationId = self::defaultLocationId();

        return $locationId !== null ? Location::query()->find($locationId) : null;
    }

    /**
     * The first location (the default tenant) used for every flow that does
     * not resolve a location explicitly.
     */
    private static function defaultLocationId(): ?int
    {
        // Not cached: the id is only valid for the current database state, and
        // a cached id would go stale across test transactions (and any other
        // lifecycle where rows are rolled back).
        try {
            $defaultLocationId = Location::query()->orderBy('id')->value('id');

            return $defaultLocationId !== null ? (int) $defaultLocationId : null;
        } catch (\Throwable) {
            // The database is not available yet (e.g. unit tests running
            // against a raw application).
            return null;
        }
    }
}
