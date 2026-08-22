<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Broadcast the admin panel theme preset (light/dark/system) chosen by an
 * admin, so open admin tabs live-update. Public channel: presets are not
 * sensitive, and admins are already authenticated for the panel itself.
 */
class AdminThemeChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $preset,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('admin.theme'),
        ];
    }
}
