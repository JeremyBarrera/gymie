<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class LocationThemeChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $locationId,
        public string $locationToken,
        
        public array $colors,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('location.theme.'.$this->locationToken),
        ];
    }
}
