<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the Filament panel off the public funnel door.
 *
 * The ISP blocks inbound ports, so internet traffic arrives exclusively
 * through Tailscale Funnel, which proxies to nginx with the public funnel
 * hostname as Host. The panel is registered at path `/` — same root the
 * member-facing pages share — so a path-based guard cannot separate them.
 * This middleware denies by HOST instead: requests arriving with the
 * public funnel hostname get a 404 for every panel route, while LAN and
 * tailnet hostnames keep full access. Authoritative server-side check, not
 * an nginx convention.
 */
class RestrictPanelToPrivateHosts
{
    public function handle(Request $request, Closure $next): Response
    {
        $funnelHost = rtrim((string) config('gymie.funnel_host'), '.');

        if ($funnelHost !== '' && strcasecmp($request->getHost(), $funnelHost) === 0) {
            abort(404);
        }

        return $next($request);
    }
}
