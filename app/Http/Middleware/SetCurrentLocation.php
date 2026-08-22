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

/**
 * Resolves the current location (tenant) for the request and pins it on the
 * tenant context.
 *
 * - Authenticated requests resolve their location set themselves; the `owner`
 *   role pins nothing, so its queries stay unscoped and see every location.
 * - Unauthenticated member-facing routes resolve the location from the
 *   location token (`/checkin/{token}`, `/signup/{token}`, and the `token`
 *   posted to `/checkin/submit`) or the queue entry uuid (`/waiting/{uuid}`).
 * - Everything else falls back to the default location.
 */
class SetCurrentLocation
{
    public function handle(Request $request, Closure $next): Response
    {
        // The tenant context is a process-wide singleton (e.g. under Octane),
        // so a pin left over from a previous request must never leak into
        // this one. Each request re-resolves its own tenant below.
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

        // The public API endpoints identify the location via `location_token`
        // (`/api/v1/signup/apply`), so resolve the tenant from it too.
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
