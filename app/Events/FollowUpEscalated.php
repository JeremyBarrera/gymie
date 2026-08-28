<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class FollowUpEscalated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        private int $userId,
        public string $notification_id,
        public string $action,
        public ?string $reason,
        public array $actor,
        public array $member,
        public ?int $subscription_id,
        public ?int $invoice_id,
        public string $occurred_at,
    ) {}

    

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.'.$this->userId),
        ];
    }

    

    public function broadcastWith(): array
    {
        return [
            'notification_id' => $this->notification_id,
            'action' => $this->action,
            'reason' => $this->reason,
            'actor' => $this->actor,
            'member' => $this->member,
            'subscription_id' => $this->subscription_id,
            'invoice_id' => $this->invoice_id,
            'occurred_at' => $this->occurred_at,
        ];
    }
}
