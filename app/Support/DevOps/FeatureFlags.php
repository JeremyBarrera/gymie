<?php

namespace App\Support\DevOps;

use App\Contracts\TenantContext;
use App\Models\User;
use App\Services\LocationTenantContext;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/**
 * Feature-flag resolution combining the global (gym-scoped) operator toggle
 * with optional per-user overrides.
 *
 * The Pennant scope is pinned to the current gym, so `Feature::active()` is
 * the global operator switch. A per-user override is stored under the user's
 * own scope; when one exists it wins over the global switch, otherwise the
 * global switch decides.
 */
final class FeatureFlags
{
    /**
     * Whether the given feature is active for the given user, honouring a
     * per-user override when one has been set and otherwise falling back to
     * the global (gym-scoped) operator toggle.
     */
    public static function activeForUser(?User $user, string $name): bool
    {
        if ($user === null) {
            return Feature::active($name);
        }

        if (self::hasOverride($user, $name)) {
            return (bool) Feature::for($user)->value($name);
        }

        if ($user->isOwner()) {
            return self::globalActiveForOwner($name);
        }

        return Feature::active($name);
    }

    /**
     * Owners resolve no location, so the gym-wide kill switch is read from
     * both the default location's scope and the global (null) scope. The
     * flag is off when either row disables it, regardless of whether the
     * switch was flipped before or after the location existed.
     */
    private static function globalActiveForOwner(string $name): bool
    {
        $context = app(TenantContext::class);

        $defaultLocation = $context instanceof LocationTenantContext
            ? $context->defaultLocation()
            : null;

        return Feature::for([$defaultLocation, null])->active($name);
    }

    /**
     * Whether a per-user override row exists for the given feature.
     */
    public static function hasOverride(User $user, string $name): bool
    {
        return DB::table(self::table())
            ->where('name', $name)
            ->where('scope', Feature::serializeScope($user))
            ->exists();
    }

    private static function table(): string
    {
        $store = config('pennant.default', 'database');
        $table = config("pennant.stores.{$store}.table");

        return is_string($table) && $table !== '' ? $table : 'features';
    }
}
