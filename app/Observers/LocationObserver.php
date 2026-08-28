<?php

namespace App\Observers;

use App\Events\LocationThemeChanged;
use App\Models\Location;
use App\Support\ColorContrast;

class LocationObserver
{
    

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
