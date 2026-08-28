<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ForceJsonResponse
{
    

    public function handle(Request $request, Closure $next): Response
    {
        $accept = (string) $request->headers->get('Accept', '');

        if (! str_contains($accept, 'application/json')) {
            $request->headers->set('Accept', 'application/json');
        }

        return $next($request);
    }
}
