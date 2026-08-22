<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Broadcast whenever a location's theme colors change, so open visitor
 * screens (scan / waiting) can live-update their palette without a reload.
 * Public channel: visitor screens are not authenticated.
 */
class LocationThemeChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $locationId,
        public string $locationToken,
        /** @var array<string, string> Derived `--c-*` palette from ColorContrast::derivePalette(). */
        public array $colors,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('location.theme.'.$this->locationToken),
        ];
    }
}
