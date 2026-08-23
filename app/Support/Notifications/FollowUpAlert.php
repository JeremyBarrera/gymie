<?php

namespace App\Support\Notifications;

use App\Events\FollowUpEscalated;
use App\Models\Invoice;
use App\Models\Member;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\FollowUpAlertNotification;

/**
 * Entry point for follow-up alerts (LIVE_RECEPTION_FLOW_PLAN.md).
 *
 * Resolves recipients from the settings scope `follow_up`, persists one
 * contract-payload notification per recipient and escalates each over its
 * private `user.{id}` channel. Call sites never change.
 */
final class FollowUpAlert
{
    private function __construct() {}

    /**
     * Persist a follow-up alert for every resolved recipient.
     *
     * @param  string  $action  One of: override_checkin|new_subscription|payment_added|due_date_changed.
     */
    public static function send(
        string $action,
        Member $member,
        User $actor,
        ?string $reason,
        ?Subscription $subscription = null,
        ?Invoice $invoice = null,
    ): void {
        $payload = [
            'action' => $action,
            'reason' => $reason,
            'actor' => [
                'id' => $actor->id,
                'name' => $actor->name,
            ],
            'member' => [
                'id' => $member->id,
                'name' => $member->name,
                'code' => $member->code,
            ],
            'subscription_id' => $subscription?->id,
            'invoice_id' => $invoice?->id,
            'occurred_at' => now()->toISOString(),
        ];

        foreach (NotificationRecipients::resolve('follow_up') as $recipient) {
            $notification = new FollowUpAlertNotification($payload);
            $recipient->notify($notification);

            FollowUpEscalated::dispatch(
                $recipient->id,
                (string) $notification->id,
                $payload['action'],
                $payload['reason'],
                $payload['actor'],
                $payload['member'],
                $payload['subscription_id'],
                $payload['invoice_id'],
                $payload['occurred_at'],
            );
        }
    }
}
