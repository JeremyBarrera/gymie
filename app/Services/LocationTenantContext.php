<?php

namespace App\Services;

use App\Contracts\TenantContext;
use App\Models\Location;
use App\Models\User;
use App\Support\Locations\LocationAccess;
use Illuminate\Support\Facades\Auth;

class LocationTenantContext implements TenantContext
{
    private static ?int $pinnedLocationId = null;

    
    
    private static bool $resolvedDefaultLocationId = false;

    private static ?int $defaultLocationId = null;

    

    public static function setLocationId(?int $locationId): void
    {
        self::$pinnedLocationId = $locationId;
    }

    

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

    

    public function location(): ?Location
    {
        $locationId = $this->locationId();

        return $locationId !== null ? Location::query()->find($locationId) : null;
    }

    

    public function defaultLocation(): ?Location
    {
        $locationId = self::defaultLocationId();

        return $locationId !== null ? Location::query()->find($locationId) : null;
    }

    

    private static function defaultLocationId(): ?int
    {
        
        
        
        try {
            $defaultLocationId = Location::query()->orderBy('id')->value('id');

            return $defaultLocationId !== null ? (int) $defaultLocationId : null;
        } catch (\Throwable) {
            
            
            return null;
        }
    }
}
