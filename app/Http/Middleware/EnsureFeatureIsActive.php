<?php

namespace App\Http\Middleware;

use App\Support\DevOps\FeatureFlags;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureFeatureIsActive
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (! FeatureFlags::activeForUser(Auth::user(), $feature)) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => __('app.api.feature_disabled'),
                ], 403);
            }

            abort(503);
        }

        return $next($request);
    }
}
