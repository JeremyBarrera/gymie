<?php

namespace App\Filament\Concerns;

use App\Events\QueueEntryClaimed;
use App\Events\QueueEntryReleased;
use App\Models\Location;
use App\Models\LocationToken;
use App\Models\QueueEntry;
use Illuminate\Support\Facades\Auth;

trait ClaimsQueueEntries
{
    

    public const STALE_CLAIM_SECONDS = 60;

    

    public function updated($name, $value): void
    {
        foreach (['selectedQueueEntryId', 'selectedCheckInEntryId'] as $prop) {
            $entryId = $this->{$prop} ?? null;

            if (! $entryId) {
                continue;
            }

            QueueEntry::where('id', $entryId)
                ->where('status', 'attending')
                ->where('claimed_by_user_id', (int) Auth::id())
                ->update(['claimed_at' => now()]);
        }
    }

    

    protected function ensureClaimed(QueueEntry $entry): bool
    {
        if ((int) $entry->claimed_by_user_id === (int) Auth::id()) {
            return true;
        }

        QueueEntry::where('id', $entry->id)
            ->where('status', 'attending')
            ->whereNotNull('claimed_at')
            ->where('claimed_at', '<', now()->subSeconds(self::STALE_CLAIM_SECONDS))
            ->update([
                'claimed_by_user_id' => null,
                'claimed_at' => null,
                'status' => 'waiting',
            ]);

        $claimed = QueueEntry::where('id', $entry->id)
            ->where('status', 'waiting')
            ->whereNull('claimed_by_user_id')
            ->update([
                'claimed_by_user_id' => Auth::id(),
                'claimed_at' => now(),
                'status' => 'attending',
            ]);

        if (! $claimed) {
            return false;
        }

        $this->broadcastClaimed($entry);

        return true;
    }

    

    protected function releaseClaim(int $queueEntryId): void
    {
        $released = QueueEntry::where('id', $queueEntryId)
            ->where('status', 'attending')
            ->where('claimed_by_user_id', (int) Auth::id())
            ->update([
                'claimed_by_user_id' => null,
                'claimed_at' => null,
                'status' => 'waiting',
            ]);

        if (! $released) {
            return;
        }

        $entry = QueueEntry::find($queueEntryId);

        if (! $entry) {
            return;
        }

        $locationToken = LocationToken::where('tokenable_type', Location::class)
            ->where('tokenable_id', $entry->location_id)
            ->where('kind', $entry->kind)
            ->value('token');

        if ($locationToken) {
            broadcast(new QueueEntryReleased(
                $entry->id,
                $entry->uuid,
                $locationToken,
                $entry->kind,
                $entry->payload
            ))->toOthers();
        }
    }

    protected function broadcastClaimed(QueueEntry $entry): void
    {
        $locationToken = LocationToken::where('tokenable_type', Location::class)
            ->where('tokenable_id', $entry->location_id)
            ->where('kind', $entry->kind)
            ->value('token');

        if ($locationToken) {
            broadcast(new QueueEntryClaimed(
                $entry->id,
                $entry->uuid,
                $locationToken,
                $entry->kind,
                $entry->payload
            ))->toOthers();
        }
    }
}
