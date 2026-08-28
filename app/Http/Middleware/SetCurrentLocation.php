<?php

namespace App\Http\Middleware;

use App\Models\Location;
use App\Models\LocationToken;
use App\Models\QueueEntry;
use App\Models\User;
use App\Services\LocationTenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SetCurrentLocation
{
    public function handle(Request $request, Closure $next): Response
    {
        
        
        
        LocationTenantContext::setLocationId(null);

        $user = $request->user();

        if ($user instanceof User) {
            return $next($request);
        }

        $locationId = $this->resolveLocationFromRoute($request);

        if ($locationId !== null) {
            LocationTenantContext::setLocationId($locationId);
        }

        return $next($request);
    }

    private function resolveLocationFromRoute(Request $request): ?int
    {
        $token = $request->route('token');

        if (! is_string($token) || $token === '') {
            $token = $request->input('token');
        }

        
        
        if (! is_string($token) || $token === '') {
            $token = $request->input('location_token');
        }

        if (is_string($token) && $token !== '') {
            $locationToken = LocationToken::query()
                ->withoutGlobalScopes()
                ->where('token', $token)
                ->first();

            if ($locationToken !== null) {
                $tokenable = $locationToken->tokenable_type::query()
                    ->withoutGlobalScopes()
                    ->whereKey($locationToken->tokenable_id)
                    ->first();

                $locationId = $tokenable?->getAttribute('location_id');

                if ($locationId === null && $tokenable instanceof Location) {
                    $locationId = $tokenable->getKey();
                }

                if ($locationId !== null) {
                    return (int) $locationId;
                }
            }
        }

        $uuid = $request->route('uuid');

        if (is_string($uuid) && $uuid !== '' && Str::isUuid($uuid)) {
            $locationId = QueueEntry::query()
                ->withoutGlobalScopes()
                ->where('uuid', $uuid)
                ->value('location_id');

            if ($locationId !== null) {
                return (int) $locationId;
            }
        }

        return null;
    }
}
