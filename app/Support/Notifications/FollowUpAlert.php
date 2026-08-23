<?php

namespace App\Support\Notifications;

use App\Models\Invoice;
use App\Models\Member;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\ReceptionOverrideNotification;

/**
 * Entry point for follow-up alerts (LIVE_RECEPTION_FLOW_PLAN.md).
 *
 * Resolves recipients from the settings scope `follow_up` and persists one
 * database notification per recipient. Delivery internals are swapped behind
 * this helper in Phase O2 — call sites never change.
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
            $recipient->notify(ReceptionOverrideNotification::followUp($member, $actor, $payload));
        }
    }
}
