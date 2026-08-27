<?php

namespace App\Filament\Concerns;

use App\Enums\Status;
use App\Events\QueueEntryResolved;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\LocationToken;
use App\Models\Member;
use App\Models\QueueEntry;
use App\Models\Subscription;
use App\Services\Membership\PlanCheckInService;
use App\Support\DevOps\FeatureFlags;
use App\Support\Locations\LocationAccess;
use App\Support\Notifications\FollowUpAlert;
use App\Support\Notifications\NotificationRecipients;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;

/**
 * Shared check-in verification flow used by the Reception page and the
 * global LiveSignupPopup component: a live popup opens when a check-in
 * queue entry arrives, shows the matching profile (or a candidate picker
 * when the identifier matches more than one member), and lets staff
 * approve (picking the service the member checks in against) or deny the
 * check-in. Non-access services can be overridden after confirmation.
 *
 * The same overlay doubles as the manual walk-up flow: staff searches a
 * member directly (no queue entry exists), and every entry-dependent step
 * (status updates, broadcasts, resolution notifications) is skipped.
 */
trait HandlesCheckInVerification
{
    public bool $showCheckInOverlay = false;

    public ?int $selectedCheckInEntryId = null;

    /** The member chosen for the entry; auto-resolved for single matches. */
    public ?int $selectedCheckInMemberId = null;

    /** The service (from the location's services) the member checks in against. */
    public ?int $checkInServiceId = null;

    /** True when the overlay was opened by the manual walk-up search instead of a queue entry. */
    public bool $checkInManualMode = false;

    /** @var array<int> Member ids found by the manual walk-up search (picker shown when several). */
    public array $manualCheckInCandidates = [];

    /** Manual walk-up search term (name, contact, code or government ID). */
    public string $manualCheckInSearch = '';

    public bool $checkInDenyStep = false;

    public string $checkInDenyReason = '';

    /** Override confirm step: which recipients will be notified + optional reason. */
    public bool $checkInOverrideStep = false;

    public string $checkInOverrideReason = '';

    /** @var array<string> Recipient names shown on the override confirm step. */
    public array $checkInOverrideRecipients = [];

    /** Same-day duplicate alert step for limited plans (gym timezone, approved-only). */
    public bool $checkInSameDayDuplicateStep = false;

    /** @var array<int> Check-in entries that arrived while the overlay was already open. */
    public array $checkInPopupQueue = [];

    /**
     * Open the check-in overlay for a live check-in entry, or queue it when
     * the overlay is already showing another entry.
     */
    public function queueOrOpenCheckIn(QueueEntry $entry): void
    {
        if (! in_array($entry->status, ['waiting', 'attending'], true)) {
            return;
        }

        if ($this->showCheckInOverlay) {
            $this->checkInPopupQueue[] = $entry->id;
        } else {
            $this->openCheckInOverlay($entry->id);
        }
    }

    /**
     * Run the manual walk-up search and open the shared check-in overlay:
     * exactly one active match opens the profile straight away, several
     * matches open it on the candidate picker step.
     */
    public function openManualCheckInOverlay(): void
    {
        $search = trim($this->manualCheckInSearch);

        if ($search === '') {
            return;
        }

        $matches = Member::searchByIdentifier($search);
        $eligible = $matches
            ->filter(fn (Member $member): bool => $member->checkInBlocker() === null)
            ->values();

        if ($eligible->isEmpty()) {
            // Banned is the only member-level refusal; lifecycle statuses
            // walk up like anyone else.
            $this->dispatch('notify',
                type: 'danger',
                message: $matches->isNotEmpty()
                    ? __('app.reception.check_in_member_banned')
                    : __('app.reception.manual_no_match', ['search' => $search]),
            );

            return;
        }

        $this->beginManualCheckIn($eligible);
    }

    /**
     * Open the overlay in manual mode for the given candidates. A single
     * candidate skips the picker, mirroring how single-candidate queue
     * entries behave.
     *
     * @param  Collection<int, Member>  $candidates
     */
    protected function beginManualCheckIn(Collection $candidates): void
    {
        $this->resetCheckInOverlay(dispatchClose: false);

        $this->checkInManualMode = true;
        $this->manualCheckInCandidates = $candidates->map(fn (Member $member): int => (int) $member->id)->values()->all();
        $this->showCheckInOverlay = true;
        $this->dispatch('open-modal', id: 'checkin-overlay');

        if ($candidates->count() === 1) {
            $this->selectedCheckInMemberId = (int) $candidates->first()->id;
            $this->autoSelectCheckInService();
        }
    }

    /**
     * The queue entry backing the open overlay, or null in manual walk-up
     * mode / when the entry disappeared or is not a check-in entry.
     */
    private function resolveCurrentCheckInEntry(): ?QueueEntry
    {
        if ($this->selectedCheckInEntryId === null) {
            return null;
        }

        $entry = QueueEntry::find($this->selectedCheckInEntryId);

        return $entry !== null && $entry->kind === 'checkin' ? $entry : null;
    }

    public function openCheckInOverlay(int $queueEntryId): void
    {
        $entry = QueueEntry::find($queueEntryId);
        if (! $entry || $entry->kind !== 'checkin') {
            return;
        }

        $candidateIds = array_map('intval', $entry->payload['candidate_member_ids'] ?? []);
        $singleId = count($candidateIds) === 1 ? $candidateIds[0] : null;
        $payloadMemberId = ! empty($candidateIds)
            ? null
            : (int) ($entry->payload['member_id'] ?? 0);

        $this->selectedCheckInEntryId = $entry->id;
        $this->selectedCheckInMemberId = $singleId ?: ($payloadMemberId ?: null);
        $this->checkInServiceId = null;
        $this->checkInDenyStep = false;
        $this->checkInDenyReason = '';
        $this->checkInOverrideStep = false;
        $this->checkInOverrideReason = '';
        $this->checkInOverrideRecipients = [];
        $this->showCheckInOverlay = true;
        $this->dispatch('open-modal', id: 'checkin-overlay');

        $this->checkInPopupQueue = array_values(array_diff($this->checkInPopupQueue, [$entry->id]));

        if ($this->selectedCheckInMemberId) {
            $this->autoSelectCheckInService();
        }
    }

    public function closeCheckInOverlay(): void
    {
        $wasManualPostSignup = $this->pendingSignupCheckInQueueId !== null && $this->checkInManualMode;

        $this->resetCheckInOverlay();
        $this->openNextCheckInFromQueue();

        if ($wasManualPostSignup) {
            $this->finalizePendingSignupCheckIn(false);
        }
    }

    private function resetCheckInOverlay(bool $dispatchClose = true): void
    {
        // Close through Filament's modal manager BEFORE the state clear can
        // morph the modal out of the DOM — an unmount while open leaves a
        // stuck semi-transparent window stacked on top of the next modal.
        if ($dispatchClose) {
            $this->dispatch('close-modal', id: 'checkin-overlay');
        }

        $closedId = (int) $this->selectedCheckInEntryId;

        $this->showCheckInOverlay = false;
        $this->selectedCheckInEntryId = null;
        $this->selectedCheckInMemberId = null;
        $this->checkInServiceId = null;
        $this->checkInDenyStep = false;
        $this->checkInDenyReason = '';
        $this->checkInOverrideStep = false;
        $this->checkInOverrideReason = '';
        $this->checkInOverrideRecipients = [];
        $this->checkInSameDayDuplicateStep = false;
        $this->checkInManualMode = false;
        $this->manualCheckInCandidates = [];
        $this->manualCheckInSearch = '';

        if ($closedId > 0) {
            $this->checkInOverlayClosed($closedId);
        }
    }

    /**
     * Hook for hosts that track check-ins closed without being attended.
     */
    protected function checkInOverlayClosed(int $queueEntryId): void {}

    private function openNextCheckInFromQueue(): void
    {
        $nextId = array_shift($this->checkInPopupQueue);
        if ($nextId !== null) {
            $this->openCheckInOverlay((int) $nextId);
        }
    }

    /**
     * Preselect the only accessible service, falling back to the first row,
     * whenever the overlay shows a resolved member.
     */
    private function autoSelectCheckInService(): void
    {
        $services = $this->checkInServices;

        if (empty($services)) {
            return;
        }

        $access = collect($services)->where('state', 'access')->values();
        $this->checkInServiceId = $access->count() === 1
            ? (int) $access->first()['id']
            : (int) $services[0]['id'];
    }

    /**
     * Resolve an ambiguous identifier: pick the correct profile among the
     * candidates carried by the entry payload, or among the manual walk-up
     * search results when no entry backs the overlay.
     */
    public function selectCheckInMember(int $memberId): void
    {
        $entry = $this->resolveCurrentCheckInEntry();

        if ($entry !== null) {
            $candidateIds = array_map('intval', $entry->payload['candidate_member_ids'] ?? []);

            if (! in_array($memberId, $candidateIds, true)) {
                return;
            }
        } elseif (! in_array($memberId, $this->manualCheckInCandidates, true)) {
            return;
        }

        $this->selectedCheckInMemberId = $memberId;
        $this->checkInServiceId = null;
        $this->checkInOverrideStep = false;

        $this->autoSelectCheckInService();
    }

    /**
     * The location the current check-in physically happens at: the queue
     * entry's own location when an entry backs the overlay, otherwise the
     * operating account's current location — the same resolution the
     * Reception queue uses. It must never resolve to the unscoped
     * owner-tenancy null, which would read as "cross-location services
     * only" and render the manual walk-up service list empty on
     * location-scoped data.
     */
    private function checkInLocation(?QueueEntry $entry): ?int
    {
        if ($entry !== null) {
            return (int) $entry->location_id;
        }

        return LocationAccess::firstAccessibleLocationId(Auth::user());
    }

    /**
     * Per-service check-in states for the selected member: at the queue
     * entry's location, or at the operating account's current location for
     * the manual walk-up flow (see `PlanCheckInService::serviceStatesForMember()`).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCheckInServicesProperty(): array
    {
        if (! $this->selectedCheckInMemberId) {
            return [];
        }

        $member = Member::find($this->selectedCheckInMemberId);

        if (! $member) {
            return [];
        }

        $entry = $this->resolveCurrentCheckInEntry();

        if ($this->selectedCheckInEntryId !== null && $entry === null) {
            return [];
        }

        return app(PlanCheckInService::class)->serviceStatesForMember(
            $member,
            $this->checkInLocation($entry),
        );
    }

    public function updatedCheckInServiceId($value): void
    {
        if (blank($value)) {
            $this->checkInServiceId = null;
            $this->checkInOverrideStep = false;
            $this->checkInSameDayDuplicateStep = false;

            return;
        }

        $serviceId = (int) $value;
        $valid = collect($this->checkInServices)
            ->contains(fn (array $row): bool => (int) $row['id'] === $serviceId);

        if (! $valid) {
            $this->checkInServiceId = null;
        }

        $this->checkInOverrideStep = false;
        $this->checkInSameDayDuplicateStep = false;
    }

    public function selectCheckInService(int $serviceId): void
    {
        if (! $this->selectedCheckInMemberId) {
            return;
        }

        $this->checkInSameDayDuplicateStep = false;

        $valid = collect($this->checkInServices)
            ->contains(fn (array $row): bool => (int) $row['id'] === $serviceId);

        if (! $valid) {
            return;
        }

        $this->checkInServiceId = $serviceId;
        $this->checkInOverrideStep = false;
    }

    /**
     * Broadcast the queue entry's resolution to its location scanner, when
     * the overlay is backed by a queue entry at all (manual walk-ups have
     * nothing to broadcast to).
     */
    private function broadcastCheckInResolution(QueueEntry $entry, bool $approved, ?string $reason): void
    {
        $locationToken = LocationToken::where('tokenable_type', Location::class)
            ->where('tokenable_id', $entry->location_id)
            ->where('kind', 'checkin')
            ->value('token');

        if ($locationToken) {
            broadcast(new QueueEntryResolved(
                $entry->id,
                $entry->uuid,
                $locationToken,
                'checkin',
                $entry->payload,
                $approved,
                $reason
            ))->toOthers();
        }
    }

    public function approveCheckIn(): void
    {
        if (! $this->selectedCheckInMemberId || ! $this->checkInServiceId) {
            return;
        }

        $entry = $this->resolveCurrentCheckInEntry();

        if ($entry === null && $this->selectedCheckInEntryId !== null) {
            $this->closeCheckInOverlay();

            return;
        }

        if ($entry !== null && ! in_array($entry->status, ['waiting', 'attending'], true)) {
            $this->closeCheckInOverlay();

            return;
        }

        $row = collect($this->checkInServices)->firstWhere('id', $this->checkInServiceId);
        $member = Member::find($this->selectedCheckInMemberId);
        $subscription = $row ? Subscription::find($row['subscription_id']) : null;

        if (! $member || ! $subscription || ($row['state'] ?? null) !== 'access') {
            $this->dispatch('notify',
                type: 'danger',
                message: __('app.notifications.check_in_failed'),
            );

            return;
        }

        try {
            app(PlanCheckInService::class)->checkIn(
                $member,
                $subscription,
                Auth::user(),
                false,
                $this->checkInLocation($entry),
            );
        } catch (\Throwable $exception) {
            $this->dispatch('notify',
                type: 'danger',
                message: $exception->getMessage(),
            );

            return;
        }

        if ($entry !== null) {
            $entry->update(['status' => 'approved', 'override' => false]);

            $this->broadcastCheckInResolution($entry, true, null);
        }

        $this->dispatch('notify',
            type: 'success',
            message: __('app.reception.checkin_approved', ['name' => $member->name]),
        );

        $wasManualPostSignup = $this->pendingSignupCheckInQueueId !== null && $entry === null;

        $this->resetCheckInOverlay();

        if ($wasManualPostSignup) {
            $this->finalizePendingSignupCheckIn(true);
        }

        if ($entry !== null) {
            $this->removeCheckInFromView($entry->id);
        }
    }

    /**
     * Move the overlay to the override confirm step for the given service:
     * shows who will be notified, with an optional reason.
     *
     * The no-access and uses-exhausted states offer the generic override —
     * expired goes through the renewal modal and past-due states through the
     * payment / due-date modals instead.
     */
    public function openCheckInOverrideFor(int $serviceId): void
    {
        $this->selectCheckInService($serviceId);

        if ($this->checkInServiceId !== $serviceId) {
            return;
        }

        $row = collect($this->checkInServices)->firstWhere('id', $serviceId);

        if (! in_array($row['state'] ?? null, ['no_access', 'uses_exhausted'], true)) {
            return;
        }

        $this->checkInOverrideReason = '';
        $this->checkInOverrideRecipients = NotificationRecipients::resolve('override')
            ->pluck('name')
            ->all();

        $this->checkInOverrideStep = true;
    }

    public function confirmCheckInOverride(): void
    {
        if (! $this->selectedCheckInMemberId || ! $this->checkInServiceId) {
            return;
        }

        if (! FeatureFlags::activeForUser(Auth::user(), 'checkin.override')) {
            $this->dispatch('notify',
                type: 'danger',
                message: __('app.reception.override_disabled'),
            );

            return;
        }

        $entry = $this->resolveCurrentCheckInEntry();

        if ($entry !== null && ! in_array($entry->status, ['waiting', 'attending'], true)) {
            $this->closeCheckInOverlay();

            return;
        }

        $row = collect($this->checkInServices)->firstWhere('id', $this->checkInServiceId);
        $member = Member::find($this->selectedCheckInMemberId);
        $subscription = $row ? Subscription::find($row['subscription_id']) : null;
        $state = $row['state'] ?? null;
        $overrideReason = match ($state) {
            'no_access' => 'no_subscription',
            'uses_exhausted' => 'uses_exhausted',
            default => null,
        };

        if (! $member || ! in_array($state, ['no_access', 'uses_exhausted'], true)) {
            $this->dispatch('notify',
                type: 'danger',
                message: __('app.notifications.check_in_failed'),
            );

            return;
        }

        $reason = $this->checkInOverrideReason ?: $overrideReason;

        try {
            app(PlanCheckInService::class)->checkInOverride(
                $member,
                $subscription,
                Auth::user(),
                $reason,
                false,
                $this->checkInServiceId,
                $this->checkInLocation($entry),
            );
        } catch (\Throwable $exception) {
            $this->dispatch('notify',
                type: 'danger',
                message: $exception->getMessage(),
            );

            return;
        }

        if ($entry !== null) {
            $entry->update([
                'status' => 'approved',
                'override' => true,
                'override_by_user_id' => Auth::id(),
                'override_reason' => $this->checkInOverrideReason ?: null,
            ]);

            $this->broadcastCheckInResolution($entry, true, null);
        }

        FollowUpAlert::send(
            action: $state === 'uses_exhausted' ? 'uses_exhausted_override' : 'override_checkin',
            member: $member,
            actor: Auth::user(),
            reason: $reason,
            subscription: $subscription,
        );

        $this->dispatch('notify',
            type: 'warning',
            message: __('app.reception.override_approved'),
        );

        $wasManualPostSignup = $this->pendingSignupCheckInQueueId !== null && $entry === null;

        $this->resetCheckInOverlay();

        if ($wasManualPostSignup) {
            $this->finalizePendingSignupCheckIn(true);
        }

        if ($entry !== null) {
            $this->removeCheckInFromView($entry->id);
        }
    }

    public function confirmSameDayDuplicateCheckIn(): void
    {
        if (! $this->selectedCheckInMemberId || ! $this->checkInServiceId) {
            return;
        }

        $entry = $this->resolveCurrentCheckInEntry();

        if ($entry !== null && ! in_array($entry->status, ['waiting', 'attending'], true)) {
            $this->closeCheckInOverlay();

            return;
        }

        $row = collect($this->checkInServices)->firstWhere('id', $this->checkInServiceId);
        $member = Member::find($this->selectedCheckInMemberId);
        $subscription = $row ? Subscription::find($row['subscription_id']) : null;

        if (! $member || ($row['state'] ?? null) !== 'same_day_duplicate' || ! $subscription) {
            $this->dispatch('notify',
                type: 'danger',
                message: __('app.notifications.check_in_failed'),
            );

            return;
        }

        try {
            app(PlanCheckInService::class)->checkInOverride(
                $member,
                $subscription,
                Auth::user(),
                'same_day_duplicate',
                false,
                $this->checkInServiceId,
                $this->checkInLocation($entry),
            );
        } catch (\Throwable $exception) {
            $this->dispatch('notify',
                type: 'danger',
                message: $exception->getMessage(),
            );

            return;
        }

        if ($entry !== null) {
            $entry->update([
                'status' => 'approved',
                'override' => true,
                'override_by_user_id' => Auth::id(),
                'override_reason' => 'same_day_duplicate',
            ]);

            $this->broadcastCheckInResolution($entry, true, null);
        }

        $wasManualPostSignup = $this->pendingSignupCheckInQueueId !== null && $entry === null;

        $this->dispatch('notify',
            type: 'success',
            message: __('app.reception.same_day_duplicate_approved'),
        );

        $this->resetCheckInOverlay();

        if ($wasManualPostSignup) {
            $this->finalizePendingSignupCheckIn(true);
        }

        if ($entry !== null) {
            $this->removeCheckInFromView($entry->id);
        }
    }

    public function denySameDayDuplicateCheckIn(): void
    {
        $entry = $this->resolveCurrentCheckInEntry();

        if ($entry === null && $this->selectedCheckInEntryId !== null) {
            $this->closeCheckInOverlay();

            return;
        }

        if ($entry !== null && ! in_array($entry->status, ['waiting', 'attending'], true)) {
            $this->closeCheckInOverlay();

            return;
        }

        $row = collect($this->checkInServices)->firstWhere('id', $this->checkInServiceId);
        $member = Member::find($this->selectedCheckInMemberId);

        if (! $member || ($row['state'] ?? null) !== 'same_day_duplicate') {
            $this->dispatch('notify',
                type: 'danger',
                message: __('app.notifications.check_in_failed'),
            );

            return;
        }

        // Audit the denial as an override-type record with distinct reason, then deny the queue entry.
        try {
            $subscription = $row ? Subscription::find($row['subscription_id']) : null;

            app(PlanCheckInService::class)->checkInOverride(
                $member,
                $subscription,
                Auth::user(),
                'same_day_duplicate_denied',
                false,
                $this->checkInServiceId,
                $this->checkInLocation($entry),
            );
        } catch (\Throwable) {
            // Audit failure should not block the deny itself.
        }

        $wasManualPostSignup = $this->pendingSignupCheckInQueueId !== null && $entry === null;

        if ($entry !== null) {
            $entry->update([
                'status' => 'denied',
                'denied_reason' => __('app.reception.same_day_duplicate_denied_reason'),
                'override' => true,
                'override_by_user_id' => Auth::id(),
                'override_reason' => 'same_day_duplicate_denied',
            ]);

            $this->broadcastCheckInResolution($entry, false, __('app.reception.same_day_duplicate_denied_reason'));
        }

        $this->dispatch('notify',
            type: 'success',
            message: __('app.reception.denied'),
        );

        $this->resetCheckInOverlay();

        if ($wasManualPostSignup) {
            $this->finalizePendingSignupCheckIn(false);
        }

        if ($entry !== null) {
            $this->removeCheckInFromView($entry->id);
        }
    }

    /**
     * Open the renewal popup for an expired service row: the child Livewire
     * component receives the context and shows its own modal on top of the
     * overlay. After a successful renew + check-in both close together.
     */
    public function openExpiredSubscriptionModal(int $serviceId): void
    {
        $member = Member::find($this->selectedCheckInMemberId);
        $row = collect($this->checkInServices)->firstWhere('id', $serviceId);

        if (! $member || ! $row || ! in_array($row['state'] ?? null, ['expired', 'uses_exhausted'], true)) {
            return;
        }

        $previous = $this->latestSubscriptionForService($member, $serviceId);

        if ($previous === null) {
            $this->dispatch('notify',
                type: 'danger',
                message: __('app.notifications.check_in_not_eligible'),
            );

            return;
        }

        $this->dispatch('open-expired-subscription-modal',
            memberId: (int) $member->id,
            serviceId: (int) $serviceId,
            previousSubscriptionId: (int) $previous->id,
        );
    }

    /**
     * Open the add-payment popup for an unpaid / overdue service row.
     */
    public function openAddPaymentModal(int $serviceId): void
    {
        $context = $this->pastDueModalContext($serviceId);

        if ($context === null) {
            return;
        }

        $this->dispatch('open-add-payment-modal', ...$context);
    }

    /**
     * Open the change-due-date popup for an unpaid / overdue service row.
     */
    public function openChangeDueDateModal(int $serviceId): void
    {
        $context = $this->pastDueModalContext($serviceId);

        if ($context === null) {
            return;
        }

        $this->dispatch('open-change-due-date-modal', ...$context);
    }

    /**
     * Complete a normal check-in after a modal resolved the blocking issue:
     * the renewed subscription (expired path) or the settled invoice
     * (paid-in-full payment path).
     */
    #[On('check-in.resolved-by-modal')]
    public function completeResolvedCheckIn(int $subscriptionId): void
    {
        $entry = $this->resolveCurrentCheckInEntry();

        if ($entry === null && $this->selectedCheckInEntryId !== null) {
            $this->closeCheckInOverlay();

            return;
        }

        if ($entry !== null && ! in_array($entry->status, ['waiting', 'attending'], true)) {
            $this->closeCheckInOverlay();

            return;
        }

        $member = Member::find($this->selectedCheckInMemberId);
        $subscription = Subscription::find($subscriptionId);

        if (! $member || ! $subscription || (int) $subscription->member_id !== (int) $member->id) {
            $this->dispatch('notify',
                type: 'danger',
                message: __('app.notifications.check_in_failed'),
            );

            return;
        }

        try {
            app(PlanCheckInService::class)->checkIn(
                $member,
                $subscription,
                Auth::user(),
                false,
                $this->checkInLocation($entry),
            );
        } catch (\Throwable $exception) {
            $this->dispatch('notify',
                type: 'danger',
                message: $exception->getMessage(),
            );

            return;
        }

        if ($entry !== null) {
            $entry->update(['status' => 'approved', 'override' => false]);

            $this->broadcastCheckInResolution($entry, true, null);
        }

        $this->dispatch('notify',
            type: 'success',
            message: __('app.reception.checkin_approved', ['name' => $member->name]),
        );

        $wasManualPostSignup = $this->pendingSignupCheckInQueueId !== null && $entry === null;

        $this->resetCheckInOverlay();

        if ($wasManualPostSignup) {
            $this->finalizePendingSignupCheckIn(true);
        }

        if ($entry !== null) {
            $this->removeCheckInFromView($entry->id);
        }
    }

    /**
     * Complete an override-semantics check-in after the payment or due-date
     * modal recorded its write (both skip the overdue gate: the blocking
     * invoice was just settled or pushed out).
     */
    #[On('check-in.assisted-override')]
    public function completeAssistedOverrideCheckIn(int $serviceId, ?string $reason = null): void
    {
        if (! FeatureFlags::activeForUser(Auth::user(), 'checkin.override')) {
            $this->dispatch('notify',
                type: 'danger',
                message: __('app.reception.override_disabled'),
            );

            return;
        }

        $entry = $this->resolveCurrentCheckInEntry();

        if ($entry !== null && ! in_array($entry->status, ['waiting', 'attending'], true)) {
            $this->closeCheckInOverlay();

            return;
        }

        $row = collect($this->checkInServices)->firstWhere('id', $serviceId);
        $member = Member::find($this->selectedCheckInMemberId);
        $subscription = ($row && $row['subscription_id'] !== null)
            ? Subscription::find($row['subscription_id'])
            : null;

        if (! $member) {
            return;
        }

        try {
            app(PlanCheckInService::class)->checkInOverride(
                $member,
                $subscription,
                Auth::user(),
                $reason,
                true,
                $serviceId,
                $this->checkInLocation($entry),
            );
        } catch (\Throwable $exception) {
            $this->dispatch('notify',
                type: 'danger',
                message: $exception->getMessage(),
            );

            return;
        }

        if ($entry !== null) {
            $entry->update([
                'status' => 'approved',
                'override' => true,
                'override_by_user_id' => Auth::id(),
                'override_reason' => $reason ?: null,
            ]);

            $this->broadcastCheckInResolution($entry, true, null);
        }

        $this->dispatch('notify',
            type: 'warning',
            message: __('app.reception.override_approved'),
        );

        $wasManualPostSignup = $this->pendingSignupCheckInQueueId !== null && $entry === null;

        $this->resetCheckInOverlay();

        if ($wasManualPostSignup) {
            $this->finalizePendingSignupCheckIn(true);
        }

        if ($entry !== null) {
            $this->removeCheckInFromView($entry->id);
        }
    }

    /**
     * The most recently ending subscription of the member for a service —
     * the "expired one" whose plan preselects the renewal form and whose id
     * chains the new subscription via `renewed_from_subscription_id`.
     */
    private function latestSubscriptionForService(Member $member, int $serviceId): ?Subscription
    {
        return $member->subscriptions()
            ->with('plan')
            ->whereHas('plan', fn ($query) => $query->forService($serviceId))
            ->orderByDesc('end_date')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Shared context for the two past-due popups: validates the selected row
     * is actually unpaid/overdue and resolves the open invoice to act on.
     *
     * @return array{memberId: int, invoiceId: int, serviceId: int, subscriptionId: int|null}|null
     */
    private function pastDueModalContext(int $serviceId): ?array
    {
        $member = Member::find($this->selectedCheckInMemberId);
        $row = collect($this->checkInServices)->firstWhere('id', $serviceId);

        if (! $member || ! $row || ! in_array($row['state'] ?? null, ['unpaid', 'overdue'], true)) {
            return null;
        }

        $invoice = $this->pastDueInvoiceForMember($member, $row);

        if ($invoice === null) {
            $this->dispatch('notify',
                type: 'danger',
                message: __('app.reception.invoice_not_found'),
            );

            return null;
        }

        return [
            'memberId' => (int) $member->id,
            'invoiceId' => (int) $invoice->id,
            'serviceId' => (int) $serviceId,
            'subscriptionId' => $row['subscription_id'] !== null ? (int) $row['subscription_id'] : null,
        ];
    }

    /**
     * The member's most urgent open invoice with money due — the row's own
     * subscription's first when it has one, otherwise the earliest across
     * all subscriptions (the member-wide overdue gate).
     */
    private function pastDueInvoiceForMember(Member $member, array $row): ?Invoice
    {
        $subscriptionId = (int) ($row['subscription_id'] ?? 0);

        return Invoice::query()
            ->whereHas('subscription', fn ($query) => $query->where('member_id', $member->id))
            ->when($subscriptionId > 0, fn ($query) => $query->where('subscription_id', $subscriptionId))
            ->whereIn('status', ['issued', 'partial', 'overdue'])
            ->where('due_amount', '>', 0)
            ->orderBy('due_date')
            ->orderBy('id')
            ->first();
    }

    /**
     * Move the overlay to the deny step where an optional reason is entered.
     */
    public function denyCheckIn(): void
    {
        $this->checkInDenyStep = true;
    }

    public function confirmDenyCheckIn(): void
    {
        $entry = $this->resolveCurrentCheckInEntry();

        if ($entry === null && $this->selectedCheckInEntryId !== null) {
            $this->closeCheckInOverlay();

            return;
        }

        if ($entry !== null && ! in_array($entry->status, ['waiting', 'attending'], true)) {
            $this->closeCheckInOverlay();

            return;
        }

        $reason = $this->checkInDenyReason ?: __('app.reception.denied_no_reason');

        if ($entry !== null) {
            $entry->update([
                'status' => 'denied',
                'denied_reason' => $reason,
                'override' => false,
            ]);

            $this->broadcastCheckInResolution($entry, false, $reason);
        }

        $this->dispatch('notify', type: 'success', message: __('app.reception.denied'));

        $this->resetCheckInOverlay();

        if ($entry !== null) {
            $this->removeCheckInFromView($entry->id);
        }
    }

    /**
     * Close the check-in overlay when the entry it shows was handled or
     * expired by another tab / device, so every tab stays in sync.
     */
    public function closeCheckInIfStale(int $queueEntryId, bool $notifyHandledElsewhere): void
    {
        if (! $this->showCheckInOverlay || (int) $this->selectedCheckInEntryId !== $queueEntryId) {
            return;
        }

        $this->closeCheckInOverlay();

        if ($notifyHandledElsewhere) {
            $this->dispatch('notify',
                type: 'warning',
                message: __('app.reception.handled_elsewhere'),
            );
        }
    }

    /**
     * Hook for hosts that render a check-in queue (e.g. the Reception page).
     */
    protected function removeCheckInFromView(int $queueEntryId): void {}
}
