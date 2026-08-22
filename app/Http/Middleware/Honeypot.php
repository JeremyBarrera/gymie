<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class Honeypot
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->filled('website')) {
            abort(403);
        }

        return $next($request);
    }
}
