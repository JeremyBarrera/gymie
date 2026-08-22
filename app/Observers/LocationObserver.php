<?php

namespace App\Observers;

use App\Events\LocationThemeChanged;
use App\Models\Location;
use App\Support\ColorContrast;

class LocationObserver
{
    /**
     * Broadcast the derived palette to every token of a location whenever
     * one of its color columns changes, so open visitor screens update live.
     */
    public function updated(Location $location): void
    {
        if (! $location->wasChanged(['theme_color', 'background_color', 'accent_color'])) {
            return;
        }

        $colors = ColorContrast::derivePalette(
            $location->getEffectiveBackgroundColor(),
            $location->getEffectiveAccentColor(),
        );

        foreach ($location->tokens()->pluck('token') as $token) {
            LocationThemeChanged::dispatch($location->id, $token, $colors);
        }
    }
}
