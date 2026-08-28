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

class LocationAccess
{
    

    public static function canAccessEveryLocation(?User $user): bool
    {
        return $user !== null && $user->hasRole(PermissionFeatureFlags::OWNER_ROLE);
    }

    

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

    

    public static function canAccess(?User $user, ?int $locationId): bool
    {
        if ($locationId === null) {
            return true;
        }

        $accessible = static::accessibleLocationIds($user);

        return $accessible === null || in_array($locationId, $accessible, true);
    }

    

    public static function firstAccessibleLocationId(?User $user): ?int
    {
        $accessible = static::accessibleLocationIds($user);

        if ($accessible === null) {
            $first = Location::query()->orderBy('id')->value('id');

            return $first !== null ? (int) $first : null;
        }

        return $accessible !== [] ? $accessible[0] : null;
    }

    

    public static function accessibleLocationCount(?User $user): int
    {
        if (static::canAccessEveryLocation($user)) {
            return Location::query()->count();
        }

        return count(static::accessibleLocationIds($user) ?? []);
    }

    

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

    

    public static function scopedByAuth(): ?array
    {
        return static::accessibleLocationIds(Auth::user());
    }

    

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

    

    public static function isFoundingAdmin(?User $user): bool
    {
        return $user !== null
            && Location::query()->where('founding_admin_user_id', $user->id)->exists();
    }

    

    public static function roleLabel(string $roleName): string
    {
        return Str::headline($roleName);
    }

    

    public static function foundingAdminEmail(Location $location): string
    {
        return Str::lower(Str::slug((string) $location->name, '.')).'.admin.'.$location->id.'@gymie.local';
    }
}
