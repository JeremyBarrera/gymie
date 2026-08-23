<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Broadcast the admin panel locale chosen by an admin, so open admin tabs
 * live-update their language. Public channel: a locale code is not
 * sensitive, and admins are already authenticated for the panel itself.
 */
class LocaleChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $locale,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('admin.locale'),
        ];
    }
}
