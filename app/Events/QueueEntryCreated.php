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

    /**
     * Staff only. The payload carries applicant details (name, contact,
     * government ID), so this event must never reach the public
     * `queue.{uuid}` channel — visitors subscribe to that channel.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('location.'.$this->locationToken),
        ];
    }
}
