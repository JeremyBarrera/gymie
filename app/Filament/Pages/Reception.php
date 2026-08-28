<?php

namespace App\Filament\Pages;

use App\Events\QueueEntryClaimed;
use App\Events\QueueEntryExpired;
use App\Events\QueueEntryResolved;
use App\Filament\Concerns\HandlesCheckInVerification;
use App\Filament\Concerns\HandlesSignupVerification;
use App\Models\Location;
use App\Models\LocationToken;
use App\Models\Member;
use App\Models\QueueEntry;
use App\Models\Subscription;
use App\Services\Membership\PlanCheckInService;
use App\Support\DevOps\FeatureFlags;
use App\Support\Locations\LocationAccess;
use App\Support\Notifications\FollowUpAlert;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class Reception extends Page
{
    use HandlesCheckInVerification;
    use HandlesSignupVerification;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $slug = 'reception';

    public static function getNavigationLabel(): string
    {
        return __('app.navigation.reception');
    }

    public static function getNavigationSort(): int
    {
        return -1;
    }

    protected string $view = 'filament.pages.reception';

    public string $activeTab = 'checkin';

    public ?string $selectedQueueEntryId = null;

    public bool $showConfirmOverlay = false;

    public ?string $confirmAction = null;

    public string $denyReason = '';

    
    public array $checkinEntries = [];

    public array $signupEntries = [];

    

    public array $manualSearchResults = [];

    protected $listeners = [
        'queueEntryCreated' => 'onQueueEntryCreated',
        'queueEntryClaimed' => 'onQueueEntryClaimed',
        'queueEntryResolved' => 'onQueueEntryResolved',
        'queueEntryExpired' => 'onQueueEntryExpired',
    ];

    public function mount(): void
    {
        $this->loadQueueEntries();
    }

    public function getTitle(): string
    {
        return __('app.reception.title');
    }

    

    public function updatedManualCheckInSearch(string $value): void
    {
        $term = trim($value);

        if ($term === '') {
            $this->manualSearchResults = [];

            return;
        }

        $this->manualSearchResults = Member::searchByIdentifier($term)
            ->map(fn (Member $member): array => [
                'id' => (int) $member->id,
                'code' => (string) $member->code,
                'name' => (string) $member->name,
                'contact' => $member->contact,
            ])
            ->all();
    }

    

    public function openManualCheckInForMember(int $memberId): void
    {
        if (! collect($this->manualSearchResults)
            ->contains(fn (array $row): bool => (int) $row['id'] === $memberId)
        ) {
            return;
        }

        $member = Member::query()->find($memberId);

        if ($member === null) {
            return;
        }

        if ($member->checkInBlocker() === 'banned') {
            $this->beginManualCheckIn(collect([$member]));

            return;
        }

        $this->manualCheckInSearch = (string) $member->code;
        $this->openManualCheckInOverlay();
    }

    public function loadQueueEntries(): void
    {
        $locationId = $this->getCurrentLocationId();
        if (! $locationId) {
            $this->checkinEntries = [];
            $this->signupEntries = [];

            return;
        }

        $this->checkinEntries = QueueEntry::query()
            ->where('location_id', $locationId)
            ->where('kind', 'checkin')
            ->whereIn('status', ['waiting', 'attending'])
            ->latest('created_at')
            ->get()
            ->toArray();

        $this->signupEntries = QueueEntry::query()
            ->where('location_id', $locationId)
            ->where('kind', 'signup')
            ->whereIn('status', ['waiting', 'attending'])
            ->latest('created_at')
            ->get()
            ->toArray();

        $this->closeStaleOverlays();
    }

    

    private function closeStaleOverlays(): void
    {
        $hasSignupOverlay = $this->selectedQueueEntryId && ($this->showVerifyOverlay || $this->showConfirmOverlay);
        $hasCheckInOverlay = $this->selectedCheckInEntryId && $this->showCheckInOverlay;

        if (! $hasSignupOverlay && ! $hasCheckInOverlay) {
            return;
        }

        if ($hasSignupOverlay) {
            $entry = QueueEntry::find($this->selectedQueueEntryId);
            $stillActive = $entry !== null && in_array($entry->status, ['waiting', 'attending'], true);

            if ($stillActive) {
                return;
            }

            if ($this->showVerifyOverlay) {
                $this->closeVerifyOverlay();
            } else {
                $this->closeConfirmOverlay();
            }
        }

        if ($hasCheckInOverlay) {
            $entry = QueueEntry::find($this->selectedCheckInEntryId);
            $stillActive = $entry !== null && in_array($entry->status, ['waiting', 'attending'], true);

            if (! $stillActive) {
                $this->closeCheckInOverlay();
            }
        }
    }

    private function getCurrentLocationId(): ?int
    {
        return LocationAccess::firstAccessibleLocationId(Auth::user());
    }

    public function onQueueEntryCreated(array $payload, bool $active = true): void
    {
        $entry = QueueEntry::find($payload['queueEntryId'] ?? 0);
        if (! $entry) {
            return;
        }

        $entryArray = $entry->toArray();

        if ($entry->kind === 'checkin') {
            $this->prependEntryIfMissing($this->checkinEntries, $entryArray);

            if ($active) {
                $this->queueOrOpenCheckIn($entry);
            }
        } elseif ($entry->kind === 'signup') {
            $this->prependEntryIfMissing($this->signupEntries, $entryArray);

            if (isset($entry->payload['name'])) {
                $this->dispatch('notify',
                    type: 'success',
                    message: __('app.reception.new_signup', ['name' => $entry->payload['name']]),
                );
            }

            if ($active) {
                $this->queueOrOpenSignup($entry);
            }
        }
    }

    public function onQueueEntryClaimed(array $payload): void
    {
        $entry = QueueEntry::find($payload['queueEntryId'] ?? 0);
        if (! $entry) {
            return;
        }

        $this->updateEntryInLists($entry->id, $entry->toArray());
    }

    public function onQueueEntryResolved(array $payload): void
    {
        $entry = QueueEntry::find($payload['queueEntryId'] ?? 0);
        if (! $entry) {
            return;
        }

        $this->removeEntryFromLists($entry->id);
        $this->closeOverlayIfStale($entry->id, true);
    }

    public function onQueueEntryExpired(array $payload): void
    {
        $entry = QueueEntry::find($payload['queueEntryId'] ?? 0);
        if (! $entry) {
            return;
        }

        $this->removeEntryFromLists($entry->id);
        $this->closeOverlayIfStale($entry->id, false);
    }

    

    private function closeOverlayIfStale(int $queueEntryId, bool $notifyHandledElsewhere): void
    {
        $isConfirm = $this->showConfirmOverlay && (int) $this->selectedQueueEntryId === $queueEntryId;

        if ($isConfirm) {
            $this->closeConfirmOverlay();

            if ($notifyHandledElsewhere) {
                $this->dispatch('notify',
                    type: 'warning',
                    message: __('app.reception.handled_elsewhere'),
                );
            }

            return;
        }

        $isCheckIn = $this->showCheckInOverlay && (int) $this->selectedCheckInEntryId === $queueEntryId;

        if ($isCheckIn) {
            $this->closeCheckInOverlay();

            if ($notifyHandledElsewhere) {
                $this->dispatch('notify',
                    type: 'warning',
                    message: __('app.reception.handled_elsewhere'),
                );
            }

            return;
        }

        $this->closeVerifyIfStale($queueEntryId, $notifyHandledElsewhere);
    }

    private function prependEntryIfMissing(array &$list, array $entry): void
    {
        if (array_search($entry['id'], array_column($list, 'id'), true) === false) {
            array_unshift($list, $entry);
        }
    }

    private function removeEntryFromLists(int $queueEntryId): void
    {
        foreach (['checkinEntries', 'signupEntries'] as $listName) {
            $index = array_search($queueEntryId, array_column($this->{$listName}, 'id'));
            if ($index !== false) {
                unset($this->{$listName}[$index]);
                $this->{$listName} = array_values($this->{$listName});
                break;
            }
        }
    }

    public function deleteQueueEntry(int $queueEntryId): void
    {
        $entry = QueueEntry::find($queueEntryId);
        if (! $entry) {
            return;
        }

        $locationToken = $entry->location->tokens()
            ->where('kind', $entry->kind)
            ->value('token');

        QueueEntryExpired::dispatch($entry->id, $entry->uuid, $locationToken ?? '');

        $entry->delete();

        $this->removeEntryFromLists($queueEntryId);

        $this->dispatch('notify',
            type: 'success',
            message: __('app.reception.queue_deleted'),
        );
    }

    private function updateEntryInLists(int $queueEntryId, array $data): void
    {
        foreach (['checkinEntries', 'signupEntries'] as $listName) {
            $index = array_search($queueEntryId, array_column($this->{$listName}, 'id'));
            if ($index !== false) {
                foreach ($data as $key => $value) {
                    $this->{$listName}[$index][$key] = $value;
                }
                if (in_array($data['status'] ?? '', ['approved', 'denied', 'expired'])) {
                    unset($this->{$listName}[$index]);
                    $this->{$listName} = array_values($this->{$listName});
                }
                break;
            }
        }

    }

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;

        
        
        if ($this->showConfirmOverlay) {
            $this->dispatch('close-modal', id: 'confirm-overlay');
        }

        if ($this->showVerifyOverlay) {
            $this->dispatch('close-modal', id: 'verify-overlay');
        }

        if ($this->showCheckInOverlay) {
            $this->dispatch('close-modal', id: 'checkin-overlay');
        }

        $this->selectedQueueEntryId = null;
        $this->showConfirmOverlay = false;
        $this->showVerifyOverlay = false;
        $this->verifyStep = 1;
        $this->verifyPhoto = null;
        $this->verifyForm = [];
        $this->unseenSignupIds = [];
        $this->showCheckInOverlay = false;
        $this->selectedCheckInEntryId = null;
        $this->selectedCheckInMemberId = null;
        $this->checkInServiceId = null;
        $this->checkInDenyStep = false;
        $this->checkInDenyReason = '';
        $this->checkInOverrideStep = false;
        $this->checkInOverrideReason = '';
        $this->checkInOverrideRecipients = [];
        $this->checkInPopupQueue = [];
        $this->checkInManualMode = false;
        $this->manualCheckInCandidates = [];
        $this->manualCheckInSearch = '';
        $this->manualSearchResults = [];
    }

    public function claim(int $queueEntryId): void
    {
        $updated = QueueEntry::where('id', $queueEntryId)
            ->where('status', 'waiting')
            ->whereNull('claimed_by_user_id')
            ->update([
                'claimed_by_user_id' => Auth::id(),
                'claimed_at' => now(),
                'status' => 'attending',
            ]);

        if (! $updated) {
            $this->dispatch('notify',
                type: 'warning',
                message: __('app.reception.already_claimed'),
            );

            return;
        }

        $entry = QueueEntry::find($queueEntryId);
        if ($entry) {
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

        $this->updateEntryInLists($queueEntryId, [
            'status' => 'attending',
            'claimed_by_user_id' => Auth::id(),
            'claimed_at' => now(),
        ]);

        $this->dispatch('notify',
            type: 'success',
            message: __('app.reception.claimed'),
        );

        if ($entry && $entry->kind === 'checkin') {
            $this->openCheckInOverlay($entry->id);
        }
    }

    public function openConfirmOverlay(int $queueEntryId, string $action): void
    {
        $this->selectedQueueEntryId = $queueEntryId;
        $this->confirmAction = $action;
        $this->denyReason = '';
        $this->showConfirmOverlay = true;
        $this->dispatch('open-modal', id: 'confirm-overlay');
    }

    public function closeConfirmOverlay(): void
    {
        
        
        
        $this->dispatch('close-modal', id: 'confirm-overlay');

        $this->showConfirmOverlay = false;
        $this->selectedQueueEntryId = null;
        $this->confirmAction = null;
        $this->denyReason = '';
    }

    protected function removeApprovedSignupFromView(int $queueEntryId): void
    {
        $this->removeEntryFromLists($queueEntryId);
    }

    protected function removeCheckInFromView(int $queueEntryId): void
    {
        $this->removeEntryFromLists($queueEntryId);
    }

    public function confirm(): void
    {
        if (! $this->selectedQueueEntryId || ! $this->confirmAction) {
            return;
        }

        $entry = QueueEntry::find($this->selectedQueueEntryId);
        if (! $entry) {
            $this->closeConfirmOverlay();

            return;
        }

        $locationToken = LocationToken::where('tokenable_type', Location::class)
            ->where('tokenable_id', $entry->location_id)
            ->where('kind', $entry->kind)
            ->value('token');

        match ($this->confirmAction) {
            'approve' => $entry->kind === 'signup'
                ? $this->openVerifyOverlay($entry->id)
                : $this->approve($entry, $locationToken),
            'deny' => $this->deny($entry, $locationToken),
            'override' => $this->override($entry, $locationToken),
        };

        $this->closeConfirmOverlay();
    }

    private function approve(QueueEntry $entry, ?string $locationToken): void
    {
        if ($entry->kind === 'checkin') {
            $memberId = $entry->payload['member_id'] ?? null;
            $subscriptionId = $entry->payload['subscription_id'] ?? null;

            if ($memberId && $subscriptionId) {
                try {
                    $member = Member::findOrFail($memberId);
                    $subscription = Subscription::findOrFail($subscriptionId);

                    app(PlanCheckInService::class)->checkIn(
                        $member,
                        $subscription,
                        Auth::user(),
                        false
                    );

                    $entry->update(['status' => 'approved', 'override' => false]);
                } catch (\Throwable $e) {
                    Log::error('Reception approve failed', ['error' => $e->getMessage()]);
                    $this->dispatch('notify',
                        type: 'danger',
                        message: $e->getMessage(),
                    );

                    return;
                }
            }
        } else {
            $entry->update(['status' => 'approved', 'override' => false]);
        }

        if ($locationToken) {
            broadcast(new QueueEntryResolved(
                $entry->id,
                $entry->uuid,
                $locationToken,
                $entry->kind,
                $entry->payload,
                true,
                null
            ))->toOthers();
        }

        $this->updateEntryInLists($entry->id, ['status' => 'approved']);
        $this->dispatch('notify', type: 'success', message: __('app.reception.approved'));
    }

    private function deny(QueueEntry $entry, ?string $locationToken): void
    {
        $reason = $this->denyReason ?: __('app.reception.denied_no_reason');

        $entry->update([
            'status' => 'denied',
            'denied_reason' => $reason,
            'override' => false,
        ]);

        if ($locationToken) {
            broadcast(new QueueEntryResolved(
                $entry->id,
                $entry->uuid,
                $locationToken,
                $entry->kind,
                $entry->payload,
                false,
                $reason
            ))->toOthers();
        }

        $this->updateEntryInLists($entry->id, [
            'status' => 'denied',
            'denied_reason' => $reason,
        ]);

        $this->dispatch('notify', type: 'success', message: __('app.reception.denied'));
    }

    private function override(QueueEntry $entry, ?string $locationToken): void
    {
        if (! FeatureFlags::activeForUser(Auth::user(), 'checkin.override')) {
            $this->dispatch('notify',
                type: 'danger',
                message: __('app.reception.override_disabled'),
            );

            return;
        }

        if ($entry->kind === 'checkin') {
            $memberId = $entry->payload['member_id'] ?? null;

            if ($memberId) {
                try {
                    $member = Member::findOrFail($memberId);
                    $subscription = Subscription::find($entry->payload['subscription_id'] ?? null);

                    app(PlanCheckInService::class)->checkInOverride(
                        $member,
                        $subscription,
                        Auth::user(),
                        null,
                        false,
                        $subscription?->plan?->primaryService()?->id,
                    );

                    $entry->update([
                        'status' => 'approved',
                        'override' => true,
                        'override_by_user_id' => Auth::id(),
                    ]);

                    $this->notifyRecipients($entry, $member);
                } catch (\Throwable $e) {
                    Log::error('Reception override failed', ['error' => $e->getMessage()]);
                    $this->dispatch('notify',
                        type: 'danger',
                        message: $e->getMessage(),
                    );

                    return;
                }
            }
        } else {
            $entry->update([
                'status' => 'approved',
                'override' => true,
                'override_by_user_id' => Auth::id(),
            ]);
        }

        if ($locationToken) {
            broadcast(new QueueEntryResolved(
                $entry->id,
                $entry->uuid,
                $locationToken,
                $entry->kind,
                $entry->payload,
                true,
                null
            ))->toOthers();
        }

        $this->updateEntryInLists($entry->id, ['status' => 'approved']);
        $this->dispatch('notify', type: 'warning', message: __('app.reception.override_approved'));
    }

    private function notifyRecipients(QueueEntry $entry, Member $member): void
    {
        FollowUpAlert::send(
            action: 'override_checkin',
            member: $member,
            actor: Auth::user(),
            reason: $entry->override_reason ?: null,
            subscription: Subscription::find($entry->payload['subscription_id'] ?? null),
        );
    }
}
