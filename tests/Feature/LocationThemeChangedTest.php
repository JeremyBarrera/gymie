<?php

use App\Events\LocationThemeChanged;
use App\Models\Location;
use App\Models\LocationToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('broadcasts the derived palette to every token when a color changes', function (): void {
    Event::fake([LocationThemeChanged::class]);

    $location = Location::factory()->create([
        'background_color' => '#ffffff',
        'accent_color' => '#2563eb',
    ]);

    $tokens = LocationToken::factory()->checkin()->count(2)->create([
        'tokenable_id' => $location->id,
        'tokenable_type' => Location::class,
    ]);

    $location->update(['accent_color' => '#123456']);

    Event::assertDispatchedTimes(LocationThemeChanged::class, 2);

    Event::assertDispatched(LocationThemeChanged::class, function (LocationThemeChanged $event) use ($location, $tokens): bool {
        return $event->locationId === $location->id
            && in_array($event->locationToken, $tokens->pluck('token')->all(), true)
            && $event->colors['base'] === '#123456'
            && $event->colors['bgA'] !== $event->colors['base']
            && array_key_exists('dangerIconBg', $event->colors);
    });
});

it('does not broadcast when non-color fields change', function (): void {
    Event::fake([LocationThemeChanged::class]);

    $location = Location::factory()->create(['accent_color' => '#2563eb']);

    LocationToken::factory()->checkin()->create([
        'tokenable_id' => $location->id,
        'tokenable_type' => Location::class,
    ]);

    $location->update(['name' => 'Renamed Gym']);

    Event::assertNotDispatched(LocationThemeChanged::class);
});
