<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class QueueEntryCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $queueEntryId,
        public string $uuid,
        public string $locationToken,
        public string $kind,
        public array $payload = [],
        public ?int $createdMemberId = null,
        public ?int $position = null
    ) {}

    

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('location.'.$this->locationToken),
        ];
    }
}
