<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Follow-up alert carrying the shared payload contract
 * (action/reason/actor/member/subscription_id/invoice_id/occurred_at).
 *
 * Database-only: the websocket side rides the per-recipient
 * `FollowUpEscalated` event dispatched after each persisted row, so the
 * stored JSON and the broadcast shape never drift apart.
 */
class FollowUpAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload  The frozen alert payload contract.
     */
    public function __construct(
        public array $payload,
    ) {
        // Pre-assign the id so the persisted row and the dispatched
        // escalation share the same deterministic identifier.
        $this->id ??= Str::uuid()->toString();
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed> The contract payload, stored as-is.
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->payload;
    }
}
