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
 * hostname as Host on port 80. The panel is registered at path `/` — same
 * root the member-facing pages share — so a path-based guard cannot
 * separate them. This middleware denies by HOST + PORT instead: requests
 * arriving with the public funnel hostname on any port other than the
 * dedicated ADMIN_PORT (8443 — tailscaled's tailnet-only TLS listener)
 * get a 404 for every panel route, while LAN and tailnet doors keep full
 * access. Authoritative server-side check, not an nginx convention.
 */
class RestrictPanelToPrivateHosts
{
    /** Tailnet-only HTTPS listener that proxies to the private admin door. */
    private const ADMIN_PORT = 8443;

    public function handle(Request $request, Closure $next): Response
    {
        $funnelHost = rtrim((string) config('gymie.funnel_host'), '.');
        $isAdminDoor = $request->getPort() === self::ADMIN_PORT;

        if (! $isAdminDoor && $funnelHost !== '' && strcasecmp($request->getHost(), $funnelHost) === 0) {
            abort(404);
        }

        return $next($request);
    }
}
