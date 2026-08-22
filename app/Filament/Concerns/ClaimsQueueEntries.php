<?php

namespace App\Filament\Concerns;

use App\Events\QueueEntryClaimed;
use App\Events\QueueEntryReleased;
use App\Models\Location;
use App\Models\LocationToken;
use App\Models\QueueEntry;
use Illuminate\Support\Facades\Auth;

/**
 * Atomic claim & release for queue entries, shared by the Reception page
 * and the global LiveSignupPopup component.
 *
 * The conditional UPDATE (status=waiting AND unclaimed) makes concurrent
 * claims race-safe — exactly one staff member wins; every other tab learns
 * about it through the QueueEntryClaimed broadcast.
 */
trait ClaimsQueueEntries
{
    /**
     * How long a claim may sit without server-visible activity before a
     * colleague is allowed to take the entry over (holder walked away /
     * tab was closed). Livewire interactions inside an open overlay act
     * as heartbeats (see updated()), so active work never goes stale.
     */
    public const STALE_CLAIM_SECONDS = 60;

    /**
     * Livewire property-sync hook: every interaction while a claimed
     * overlay is open (typing, picking, uploading) refreshes the claim's
     * heartbeat so the staleness window only ever catches true absence.
     */
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

    /**
     * Claim the entry for the current user if it is still waiting and
     * unclaimed. Claims abandoned past the staleness window are released
     * first, so an absent holder never locks colleagues out. Returns true
     * when the current user holds the claim afterwards.
     */
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

    /**
     * Release the current user's claim: an overlay closed without action
     * puts the entry back into the pick-up line. Every other staff tab
     * learns about it through the QueueEntryReleased broadcast, so the
     * entry re-enters their badges live.
     */
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
                $entry->payload,
                Auth::user()->name
            ))->toOthers();
        }
    }
}
