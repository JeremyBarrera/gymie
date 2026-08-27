<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class SoundAlertsToggled implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $userId,
        public bool $enabled,
    ) {}

    /**
     * Every open panel tab of the toggling user follows along live.
     * Public channel scoped by the owning user id — mirrors the theme
     * live-sync pattern (admin.theme) for reliability: no private-auth
     * handshake, just a lightweight filter by the known GYMIE_USER_ID.
     * Payload is only a boolean, not sensitive.
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('user.'.$this->userId),
        ];
    }
}
