<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class MemberBanChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  array<int, string>  $locationTokens
     */
    public function __construct(
        public int $memberId,
        public bool $banned,
        public array $locationTokens,
    ) {}

    /**
     * Staff only: every location channel the ban affects. The payload is a
     * pure refresh signal — listeners re-fetch queue state from the database
     * instead of applying it as a client-side delta.
     */
    public function broadcastOn(): array
    {
        return collect($this->locationTokens)
            ->map(fn (string $token): PrivateChannel => new PrivateChannel('location.'.$token))
            ->values()
            ->all();
    }
}
