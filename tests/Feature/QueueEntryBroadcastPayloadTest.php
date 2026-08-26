<?php

namespace Tests\Feature;

use App\Events\QueueEntryResolved;
use Tests\TestCase;

class QueueEntryBroadcastPayloadTest extends TestCase
{
    public function test_resolved_broadcast_payload_carries_no_applicant_pii(): void
    {
        $event = new QueueEntryResolved(
            queueEntryId: 7,
            uuid: 'queue-uuid-1',
            locationToken: 'token-1',
            kind: 'signup',
            payload: [
                'name' => 'Applicant Name',
                'contact' => '+15550001111',
                'government_id' => 'ID-1234567890',
                'dob' => '1990-01-01',
            ],
            approved: false,
            deniedReason: 'Membership expired',
        );

        $payload = $event->broadcastWith();

        $this->assertSame([
            'queueEntryId' => 7,
            'uuid' => 'queue-uuid-1',
            'kind' => 'signup',
            'approved' => false,
            'deniedReason' => 'Membership expired',
            'checkedIn' => false,
        ], $payload);

        $this->assertArrayNotHasKey('payload', $payload);
    }

    public function test_resolved_event_still_reaches_the_public_queue_channel(): void
    {
        $event = new QueueEntryResolved(
            queueEntryId: 7,
            uuid: 'queue-uuid-1',
            locationToken: 'token-1',
            kind: 'checkin',
        );

        $channels = collect($event->broadcastOn())
            ->map(fn ($channel) => $channel->name)
            ->values()
            ->all();

        $this->assertContains('queue.queue-uuid-1', $channels);
    }
}
