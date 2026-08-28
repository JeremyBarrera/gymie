<?php

namespace App\Support\DevOps;

use App\Contracts\TenantContext;
use App\Models\User;
use App\Services\LocationTenantContext;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

final class FeatureFlags
{
    

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

    

    private static function globalActiveForOwner(string $name): bool
    {
        $context = app(TenantContext::class);

        $defaultLocation = $context instanceof LocationTenantContext
            ? $context->defaultLocation()
            : null;

        return Feature::for([$defaultLocation, null])->active($name);
    }

    

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
