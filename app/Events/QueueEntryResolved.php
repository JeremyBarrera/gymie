<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class QueueEntryResolved implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $queueEntryId,
        public string $uuid,
        public string $locationToken,
        public string $kind,
        public array $payload = [],
        public bool $approved = true,
        public ?string $deniedReason = null,
        public bool $checkedIn = false,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('location.'.$this->locationToken),
            new Channel('queue.'.$this->uuid),
        ];
    }

    

    public function broadcastWith(): array
    {
        return [
            'queueEntryId' => $this->queueEntryId,
            'uuid' => $this->uuid,
            'kind' => $this->kind,
            'approved' => $this->approved,
            'deniedReason' => $this->deniedReason,
            'checkedIn' => $this->checkedIn,
        ];
    }
}
