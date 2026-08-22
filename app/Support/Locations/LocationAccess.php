<?php

namespace App\Support\Locations;

use App\Models\Location;
use App\Models\User;
use App\Support\Permissions\PermissionFeatureFlags;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Resolves which locations an authenticated account may access.
 *
 * The `owner` role overrides everything and may access locations across the
 * whole platform. Every other account is limited to the rows in
 * `user_locations`, which is the location-based role/permission structure all
 * scoping flows through.
 */
class LocationAccess
{
    /**
     * Whether the account bypasses location scoping entirely.
     */
    public static function canAccessEveryLocation(?User $user): bool
    {
        return $user !== null && $user->hasRole(PermissionFeatureFlags::OWNER_ROLE);
    }

    /**
     * Location ids the account may access.
     *
     * @return list<int>|null `null` means every location (owner override)
     */
    public static function accessibleLocationIds(?User $user): ?array
    {
        if (static::canAccessEveryLocation($user)) {
            return null;
        }

        if ($user === null) {
            return [];
        }

        return $user->locations()
            ->pluck('locations.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Whether the account may access the given location.
     */
    public static function canAccess(?User $user, ?int $locationId): bool
    {
        if ($locationId === null) {
            return true;
        }

        $accessible = static::accessibleLocationIds($user);

        return $accessible === null || in_array($locationId, $accessible, true);
    }

    /**
     * The first accessible location id, or null when none are accessible.
     */
    public static function firstAccessibleLocationId(?User $user): ?int
    {
        $accessible = static::accessibleLocationIds($user);

        if ($accessible === null) {
            $first = Location::query()->orderBy('id')->value('id');

            return $first !== null ? (int) $first : null;
        }

        return $accessible !== [] ? $accessible[0] : null;
    }

    /**
     * Count of accessible locations (null for the owner means all).
     */
    public static function accessibleLocationCount(?User $user): int
    {
        if (static::canAccessEveryLocation($user)) {
            return Location::query()->count();
        }

        return count(static::accessibleLocationIds($user) ?? []);
    }

    /**
     * Options `[id => name]` of the locations the given account may assign.
     */
    public static function locationOptions(?User $user): array
    {
        $accessible = static::accessibleLocationIds($user);

        $query = Location::query();

        if ($accessible !== null) {
            $query->whereIn('id', $accessible);
        }

        return $query
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * The accessible location scope for the authenticated user.
     *
     * Convenience wrapper for resources/widgets.
     */
    public static function scopedByAuth(): ?array
    {
        return static::accessibleLocationIds(Auth::user());
    }

    /**
     * Apply an "accessible locations" filter to a query.
     *
     * Rows whose column is `null` belong to every location (the "All
     * Locations" jurisdiction) and are always included, so all-locations
     * plans and the members that follow them stay visible everywhere.
     *
     * @param  list<int>|null  $locationIds  Already-resolved ids; when null the
     *                                       authenticated account's set is used.
     */
    public static function applyAccessibleScope(Builder $query, string $column = 'location_id', ?array $locationIds = null, ?User $user = null): Builder
    {
        $locationIds ??= static::accessibleLocationIds($user ?? Auth::user());

        if ($locationIds === null) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($locationIds, $column): void {
            $query->whereIn($column, $locationIds)
                ->orWhereNull($column);
        });
    }

    /**
     * Resolve the location scope to apply from dashboard/table filters.
     *
     * Returns `null` when no filtering is needed (no authenticated user, or an
     * owner on "all locations"). Non-owners always get at least their own
     * accessible set.
     *
     * @param  array<string, mixed>  $filters
     * @return list<int>|null
     */
    public static function scopeFromFilters(?User $user, ?array $filters = null): ?array
    {
        if ($user === null) {
            return null;
        }

        $filters ??= [];

        $selected = array_values(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $filters['location_ids'] ?? []),
            static fn (int $id): bool => $id > 0,
        ));

        $accessible = static::accessibleLocationIds($user);

        if ($accessible === null) {
            return $selected !== [] ? $selected : null;
        }

        $effective = $selected !== [] ? $selected : $accessible;

        return array_values(array_intersect($effective, $accessible));
    }

    /**
     * Role options `[id => name]` an account may assign to others.
     *
     * Owners may assign any role; any other account is limited to the roles it
     * already holds, so delegated account creation can never escalate
     * privileges.
     */
    public static function roleOptions(?User $editor): array
    {
        $query = Role::query();

        if (! static::canAccessEveryLocation($editor)) {
            $roleNames = $editor?->roles()->pluck('name')->all() ?? [];
            $query->whereIn('name', $roleNames);
        }

        return $query
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Assert that the given role/location assignments stay inside the editor's
     * jurisdiction. Throws a validation exception otherwise — this is the
     * server-side backstop behind the restricted form options.
     *
     * @param  array<int, int>  $locationIds
     */
    public static function assertAssignmentsWithinJurisdiction(?User $editor, array $roleIds, array $locationIds): void
    {
        $errors = [];

        $roleOptions = static::roleOptions($editor);
        foreach ($roleIds as $roleId) {
            if (! isset($roleOptions[(int) $roleId])) {
                $errors['role'][] = __('app.validation.role_outside_jurisdiction');
                break;
            }
        }

        $accessible = static::accessibleLocationIds($editor);
        if ($accessible !== null) {
            foreach ($locationIds as $locationId) {
                if (! in_array((int) $locationId, $accessible, true)) {
                    $errors['locations'][] = __('app.validation.location_outside_jurisdiction');
                    break;
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Whether the given user is the founding admin of any location.
     */
    public static function isFoundingAdmin(?User $user): bool
    {
        return $user !== null
            && Location::query()->where('founding_admin_user_id', $user->id)->exists();
    }

    /**
     * Human label for a role name.
     */
    public static function roleLabel(string $roleName): string
    {
        return Str::headline($roleName);
    }

    /**
     * Deterministic unique email for a location's auto-created admin account.
     */
    public static function foundingAdminEmail(Location $location): string
    {
        return Str::lower(Str::slug((string) $location->name, '.')).'.admin.'.$location->id.'@gymie.local';
    }
}
