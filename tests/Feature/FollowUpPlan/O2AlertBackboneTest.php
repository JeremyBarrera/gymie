<?php

use App\Events\FollowUpEscalated;
use App\Models\Invoice;
use App\Models\Member;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\FollowUpAlertNotification;
use App\Support\Notifications\FollowUpAlert;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

afterEach(function (): void {
    \App\Helpers\Helpers::setTestSettingsOverride(null);
});

/**
 * @return array<string, mixed> The shared alert payload contract
 *                              (LIVE_RECEPTION_FLOW_PLAN.md).
 */
function o2Payload(array $overrides = []): array
{
    return array_merge([
        'action' => 'override_checkin',
        'reason' => 'gate was busy',
        'actor' => ['id' => 3, 'name' => 'Toro'],
        'member' => ['id' => 12, 'name' => 'Jane Doe', 'code' => 'GY-42'],
        'subscription_id' => 88,
        'invoice_id' => null,
        'occurred_at' => now()->toISOString(),
    ], $overrides);
}

function o2PinUsers(User ...$users): void
{
    \App\Helpers\Helpers::setTestSettingsOverride([
        'notifications' => [
            'follow_up' => [
                'roles' => ['manager'],
                'users' => collect($users)->pluck('id')->all(),
            ],
        ],
    ]);
}

it('broadcasts one escalation per instance on the recipient private channel only', function (): void {
    $notificationId = Str::uuid()->toString();
    $payload = o2Payload();
    $event = new FollowUpEscalated(
        42,
        $notificationId,
        $payload['action'],
        $payload['reason'],
        $payload['actor'],
        $payload['member'],
        $payload['subscription_id'],
        $payload['invoice_id'],
        $payload['occurred_at'],
    );

    expect($event)->toBeInstanceOf(ShouldBroadcast::class);

    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1)
        ->and($channels[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($channels[0]->name)->toBe('private-user.42')
        // Wire payload = the frozen contract + notification id, nothing else.
        ->and($event->broadcastWith())->toBe(array_merge(
            ['notification_id' => $notificationId],
            $payload,
        ));
});

it('escalates every resolved recipient over their own channel with the persisted row id', function (): void {
    Event::fake([FollowUpEscalated::class]);

    Role::firstOrCreate(['name' => 'manager']);
    $manager = User::factory()->create();
    $pinned = User::factory()->create();
    $outsider = User::factory()->create();
    o2PinUsers($pinned);
    // The role-based recipient joins via her manager role.
    $manager->assignRole('manager');

    $actor = User::factory()->create(['name' => 'Toro']);
    $member = Member::factory()->create(['name' => 'Jane Doe', 'code' => 'GY-42']);
    $subscription = Subscription::factory()->create(['member_id' => $member->id]);
    $invoice = Invoice::factory()->create(['subscription_id' => $subscription->id]);

    FollowUpAlert::send('override_checkin', $member, $actor, 'gate was busy', $subscription, $invoice);

    Event::assertDispatchedTimes(FollowUpEscalated::class, 2);

    foreach ([$manager, $pinned] as $recipient) {
        Event::assertDispatched(FollowUpEscalated::class,
            function (FollowUpEscalated $event) use ($recipient, $actor, $member, $subscription, $invoice): bool {
                /** @var DatabaseNotification $row */
                $row = $recipient->unreadNotifications()->first();

                return $event->broadcastOn()[0]->name === 'private-user.'.$recipient->id
                    && Str::isUuid($event->notification_id)
                    && $row !== null
                    && $row->getKey() === $event->notification_id
                    && $event->action === 'override_checkin'
                    && $event->reason === 'gate was busy'
                    && $event->actor === ['id' => (int) $actor->id, 'name' => 'Toro']
                    && $event->member === ['id' => (int) $member->id, 'name' => 'Jane Doe', 'code' => 'GY-42']
                    && $event->subscription_id === (int) $subscription->id
                    && $event->invoice_id === (int) $invoice->id
                    // Broadcast mirrors the persisted DB payload exactly.
                    && $row->data['action'] === $event->action
                    && $row->data['reason'] === $event->reason
                    && $row->data['occurred_at'] === $event->occurred_at;
            });
    }

    // Users outside the settings scope never receive an escalation.
    Event::assertDispatched(FollowUpEscalated::class,
        fn (FollowUpEscalated $event): bool => $event->broadcastOn()[0]->name !== 'private-user.'.$outsider->id);
});

it('keeps persistence and broadcast delivery queued off the request path', function (): void {
    Queue::fake();

    $pinnedA = User::factory()->create();
    $pinnedB = User::factory()->create();
    o2PinUsers($pinnedA, $pinnedB);

    $actor = User::factory()->create();
    $member = Member::factory()->create();

    FollowUpAlert::send('payment_added', $member, $actor, null);

    Queue::assertPushed(SendQueuedNotifications::class, 2);
    Queue::assertPushed(SendQueuedNotifications::class,
        fn (SendQueuedNotifications $job): bool => $job->notification instanceof FollowUpAlertNotification
            && $job->notifiables->contains(fn (User $notifiable): bool => $notifiable->is($pinnedA)));

    Queue::assertPushed(BroadcastEvent::class, 2);
    Queue::assertPushed(BroadcastEvent::class,
        fn (BroadcastEvent $job): bool => $job->event instanceof FollowUpEscalated
            && $job->event->action === 'payment_added');
});
