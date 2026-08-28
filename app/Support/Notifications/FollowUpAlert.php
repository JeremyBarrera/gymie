<?php

namespace App\Support\Notifications;

use App\Events\FollowUpEscalated;
use App\Models\Invoice;
use App\Models\Member;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\FollowUpAlertNotification;

final class FollowUpAlert
{
    private function __construct() {}

    

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
