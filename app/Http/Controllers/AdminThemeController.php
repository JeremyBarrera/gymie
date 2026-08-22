<?php

namespace App\Http\Controllers;

use App\Events\AdminThemeChanged;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminThemeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $preset = $request->validate([
            'preset' => ['required', Rule::in(['light', 'dark', 'system'])],
        ])['preset'];

        AdminThemeChanged::dispatch($preset);

        return response()->json(['ok' => true]);
    }
}
