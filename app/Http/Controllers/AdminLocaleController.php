<?php

namespace App\Http\Controllers;

use App\Events\LocaleChanged;
use App\Support\AppConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminLocaleController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $locale = $request->validate([
            'locale' => ['required', 'string', Rule::in(AppConfig::supportedLocales())],
        ])['locale'];

        LocaleChanged::dispatch($locale);

        return response()->json(['ok' => true]);
    }
}
