<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class QueueEntryExpired implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $queueEntryId,
        public string $uuid,
        public string $locationToken
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('location.'.$this->locationToken),
            new Channel('queue.'.$this->uuid),
        ];
    }
}
