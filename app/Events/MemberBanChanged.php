<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class MemberBanChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    

    public function __construct(
        public int $memberId,
        public bool $banned,
        public array $locationTokens,
    ) {}

    

    public function broadcastOn(): array
    {
        return collect($this->locationTokens)
            ->map(fn (string $token): PrivateChannel => new PrivateChannel('location.'.$token))
            ->values()
            ->all();
    }
}
