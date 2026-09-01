<?php

namespace App\Filament\Livewire;

use App\Filament\Concerns\HandlesCheckInVerification;
use App\Filament\Concerns\HandlesSignupVerification;
use App\Models\Member;
use App\Models\QueueEntry;
use App\Support\Locations\LocationAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class LiveSignupPopup extends Component
{
    use HandlesCheckInVerification;
    use HandlesSignupVerification;

    public ?string $selectedQueueEntryId = null;

    
    public bool $popupEnabled = true;

    
    public array $pendingQueue = [];

    
    public array $claimedQueue = [];

    
    public const CLAIM_RECOVERY_SECONDS = 300;

    protected $listeners = [
        'queueEntryCreated' => 'onQueueEntryCreated',
        'queueEntryClaimed' => 'onQueueEntryClaimed',
        'queueEntryResolved' => 'onQueueEntryResolved',
        'queueEntryExpired' => 'onQueueEntryExpired',
    ];

    public function mount(): void
    {
        $this->popupEnabled = ! request()->routeIs('filament.admin.pages.reception');
        $this->refreshPendingQueue();
    }

    

    public function refreshPendingQueue(): void
    {
        [$this->pendingQueue, $this->claimedQueue] = $this->loadQueues();
    }

    public function onQueueEntryCreated(array $payload, bool $active = true): void
    {
        $entry = QueueEntry::find($payload['queueEntryId'] ?? 0);
        if (! $entry || ! in_array($entry->status, ['waiting', 'attending'], true)) {
            return;
        }

        if ($entry->kind === 'checkin') {
            if ($active && $this->popupEnabled) {
                $this->queueOrOpenCheckIn($entry);
            } else {
                $this->addToPending($entry);
            }

            return;
        }

        if ($entry->kind !== 'signup') {
            return;
        }

        if ($active && $this->popupEnabled) {
            if (isset($entry->payload['name'])) {
                $this->dispatch('notify',
                    type: 'success',
                    message: __('app.reception.new_signup', ['name' => $entry->payload['name']]),
                );
            }

            $this->queueOrOpenSignup($entry);
        } else {
            $this->addToPending($entry);
        }
    }

    public function onQueueEntryClaimed(array $payload): void
    {
        $entryId = (int) ($payload['queueEntryId'] ?? 0);

        $this->closeCheckInIfStale($entryId, false);
        $this->closeVerifyIfStale($entryId, false);

        $index = array_search($entryId, array_column($this->pendingQueue, 'id'), true);
        if ($index === false) {
            return;
        }

        $entry = QueueEntry::find($entryId);
        if (! $entry || $entry->claimed_at === null) {
            return;
        }

        $this->claimedQueue[] = $this->claimedItem($entry, $index);
        array_splice($this->pendingQueue, $index, 1);
    }

    public function onQueueEntryResolved(array $payload): void
    {
        $entryId = (int) ($payload['queueEntryId'] ?? 0);
        $this->closeCheckInIfStale($entryId, true);
        $this->closeVerifyIfStale($entryId, true);
        $this->removeFromPending($entryId);
        $this->removeFromClaimed($entryId);
        $this->popupQueue = array_values(array_diff($this->popupQueue, [$entryId]));
    }

    public function onQueueEntryExpired(array $payload): void
    {
        $entryId = (int) ($payload['queueEntryId'] ?? 0);
        $this->closeCheckInIfStale($entryId, false);
        $this->closeVerifyIfStale($entryId, false);
        $this->removeFromPending($entryId);
        $this->removeFromClaimed($entryId);
        $this->popupQueue = array_values(array_diff($this->popupQueue, [$entryId]));
    }

    

    public function restoreClaimedToPending(int $queueEntryId): void
    {
        $index = array_search($queueEntryId, array_column($this->claimedQueue, 'id'), true);
        if ($index === false) {
            return;
        }

        $item = $this->claimedQueue[$index];
        array_splice($this->claimedQueue, $index, 1);

        $entry = QueueEntry::find($queueEntryId);
        if (! $entry || ! in_array($entry->status, ['waiting', 'attending'], true)) {
            return;
        }

        $spot = min((int) ($item['index'] ?? 0), count($this->pendingQueue));
        array_splice($this->pendingQueue, $spot, 0, [$item]);
        $this->pendingQueue = array_slice($this->pendingQueue, 0, 20);
    }

    public function verifyFromPending(int $queueEntryId): void
    {
        $this->openVerifyOverlay($queueEntryId);
        $this->removeFromPending($queueEntryId);
    }

    public function checkInFromPending(int $queueEntryId): void
    {
        $this->openCheckInOverlay($queueEntryId);
        $this->removeFromPending($queueEntryId);
    }

    protected function verifyOverlayClosed(int $queueEntryId): void
    {
        $entry = QueueEntry::find($queueEntryId);

        if ($entry && in_array($entry->status, ['waiting', 'attending'], true)) {
            $this->addToPending($entry);
        }
    }

    protected function checkInOverlayClosed(int $queueEntryId): void
    {
        $entry = QueueEntry::find($queueEntryId);

        if ($entry && in_array($entry->status, ['waiting', 'attending'], true)) {
            $this->addToPending($entry);
        }
    }

    public function render(): View
    {
        return view('livewire.live-signup-popup', [
            'enabled' => Auth::check(),
        ]);
    }

    

    private function loadQueues(): array
    {
        $query = QueueEntry::query()
            ->whereIn('status', ['waiting', 'attending'])
            ->where('expires_at', '>', now())
            ->where('created_at', '>=', now()->subMinutes(60));

        $accessible = LocationAccess::accessibleLocationIds(Auth::user());

        if ($accessible !== null) {
            $query->whereIn('location_id', $accessible);
        }

        $pending = [];
        $claimed = [];

        foreach ($query->latest('created_at')->limit(50)->get() as $entry) {
            if ($entry->status === 'attending' && $entry->claimed_at !== null && $entry->claimed_at->gte(now()->subSeconds(self::CLAIM_RECOVERY_SECONDS))) {
                $claimed[] = $this->claimedItem($entry, count($pending));

                continue;
            }

            $pending[] = $this->pendingItem($entry);
        }

        return [array_slice($pending, 0, 20), array_slice($claimed, 0, 20)];
    }

    
    private function pendingItem(QueueEntry $entry): array
    {
        $kind = (string) $entry->kind;
        $name = (string) ($entry->payload['name'] ?? '');
        $contact = (string) ($entry->payload['contact'] ?? '');

        if ($kind === 'checkin') {
            $memberId = $entry->payload['candidate_member_ids'][0] ?? $entry->payload['member_id'] ?? null;
            $member = $memberId !== null ? Member::find((int) $memberId) : null;
            $name = $member?->name ?? $name;
            $contact = $contact !== ''
                ? $contact
                : (string) ($entry->payload['identifier_value'] ?? '');
        }

        return [
            'id' => $entry->id,
            'kind' => $kind,
            'name' => $name,
            'contact' => $contact,
            'created_at' => $entry->created_at?->diffForHumans() ?? '',
        ];
    }

    

    private function claimedItem(QueueEntry $entry, int $index): array
    {
        return array_merge($this->pendingItem($entry), [
            'index' => $index,
            'seconds' => max(1, (int) $entry->claimed_at?->diffInSeconds(now()->addSeconds(self::CLAIM_RECOVERY_SECONDS)) ?? 1),
        ]);
    }

    private function addToPending(QueueEntry $entry): void
    {
        if (array_search($entry->id, array_column($this->pendingQueue, 'id'), true) === false) {
            array_unshift($this->pendingQueue, $this->pendingItem($entry));
            $this->pendingQueue = array_slice($this->pendingQueue, 0, 20);
        }
    }

    private function removeFromPending(int $queueEntryId): void
    {
        $index = array_search($queueEntryId, array_column($this->pendingQueue, 'id'), true);
        if ($index !== false) {
            unset($this->pendingQueue[$index]);
            $this->pendingQueue = array_values($this->pendingQueue);
        }
    }

    private function removeFromClaimed(int $queueEntryId): void
    {
        $index = array_search($queueEntryId, array_column($this->claimedQueue, 'id'), true);
        if ($index !== false) {
            unset($this->claimedQueue[$index]);
            $this->claimedQueue = array_values($this->claimedQueue);
        }
    }
}
