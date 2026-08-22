<?php

namespace App\Notifications;

use App\Models\Location;
use App\Models\Member;
use App\Models\QueueEntry;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class ReceptionOverrideNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public QueueEntry $queueEntry,
        public Member $member,
        public User $staff,
        public ?string $overrideReason = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    private function buildMessage(): string
    {
        $locationName = Location::find($this->queueEntry->location_id)?->name ?? __('app.reception.unknown_location');
        $params = [
            'staff' => $this->staff->name,
            'member' => $this->member->name,
            'location' => $locationName,
        ];

        return match ($this->overrideReason) {
            'expired' => __('app.reception.override_expired_message', $params),
            'no_subscription' => __('app.reception.override_no_subscription_message', $params),
            default => __('app.reception.override_message', $params),
        };
    }

    public function toDatabase(object $notifiable): array
    {
        $base = $this->buildMessage();
        $reason = $this->queueEntry->override_reason;

        return [
            'type' => 'reception_override',
            'queue_entry_id' => $this->queueEntry->id,
            'queue_entry_uuid' => $this->queueEntry->uuid,
            'member_id' => $this->member->id,
            'member_name' => $this->member->name,
            'staff_id' => $this->staff->id,
            'staff_name' => $this->staff->name,
            'location_id' => $this->queueEntry->location_id,
            'location_name' => Location::find($this->queueEntry->location_id)?->name ?? __('app.reception.unknown_location'),
            'override_reason' => $reason,
            'override_detail' => $this->overrideReason,
            'message' => $reason ? "{$base} — {$reason}" : $base,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        $base = $this->buildMessage();
        $reason = $this->queueEntry->override_reason;

        return new BroadcastMessage([
            'type' => 'reception_override',
            'queue_entry_id' => $this->queueEntry->id,
            'queue_entry_uuid' => $this->queueEntry->uuid,
            'member_id' => $this->member->id,
            'member_name' => $this->member->name,
            'staff_id' => $this->staff->id,
            'staff_name' => $this->staff->name,
            'location_id' => $this->queueEntry->location_id,
            'location_name' => Location::find($this->queueEntry->location_id)?->name ?? __('app.reception.unknown_location'),
            'override_reason' => $reason,
            'override_detail' => $this->overrideReason,
            'message' => $reason ? "{$base} — {$reason}" : $base,
        ]);
    }
}
