<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\SettingsRepository;
use App\Http\Requests\Api\V1\SettingsUpdateRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends ApiController
{
    

    public function show(Request $request): JsonResponse
    {
        $settings = app(SettingsRepository::class)->get();

        return response()->json([
            'data' => $settings,
        ]);
    }

    

    public function update(SettingsUpdateRequest $request): JsonResponse
    {
        $repo = app(SettingsRepository::class);
        $existing = $repo->get();
        $validated = $request->validated();

        $updated = array_replace_recursive($existing, $validated);

        $repo->put($updated);

        return response()->json([
            'data' => $repo->get(),
        ]);
    }
}
