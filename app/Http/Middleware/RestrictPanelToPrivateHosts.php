<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictPanelToPrivateHosts
{
    
    private const ADMIN_PORT = 8443;

    public function handle(Request $request, Closure $next): Response
    {
        $funnelHost = rtrim((string) config('gymie.funnel_host'), '.');
        $isAdminDoor = $request->getPort() === self::ADMIN_PORT;

        if (! $isAdminDoor && $funnelHost !== '' && strcasecmp($request->getHost(), $funnelHost) === 0) {
            if ($request->is('broadcasting/auth')) {
                return $next($request);
            }

            abort(404);
        }

        return $next($request);
    }
}
