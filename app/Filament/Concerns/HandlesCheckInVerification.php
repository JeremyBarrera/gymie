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
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;

trait HandlesCheckInVerification
{
    public bool $showCheckInOverlay = false;

    public ?int $selectedCheckInEntryId = null;

    
    public ?int $selectedCheckInMemberId = null;

    
    public ?int $checkInServiceId = null;

    
    public bool $checkInManualMode = false;

    
    public array $manualCheckInCandidates = [];

    
    public string $manualCheckInSearch = '';

    public bool $checkInDenyStep = false;

    public string $checkInDenyReason = '';

    
    public bool $checkInOverrideStep = false;

    public string $checkInOverrideReason = '';

    
    public array $checkInOverrideRecipients = [];

    
    public bool $checkInSameDayDuplicateStep = false;

    
    public array $checkInPopupQueue = [];

    

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
            $banned = $matches->first(fn (Member $member): bool => $member->checkInBlocker() === 'banned');

            if ($banned) {
                $this->beginManualCheckIn(collect([$banned]));

                return;
            }

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

    

    protected function checkInOverlayClosed(int $queueEntryId): void {}

    private function openNextCheckInFromQueue(): void
    {
        $nextId = array_shift($this->checkInPopupQueue);
        if ($nextId !== null) {
            $this->openCheckInOverlay((int) $nextId);
        }
    }

    

    private function autoSelectCheckInService(): void
    {
        $services = $this->checkInServices;

        if (empty($services)) {
            return;
        }

        $member = $this->selectedCheckInMemberId ? Member::find($this->selectedCheckInMemberId) : null;
        $active = $member
            ? collect($services)->filter(fn (array $row): bool => $this->hasRenewableSubscriptionForService($member, (int) $row['id']))->values()
            : collect();
        $pool = $active->isNotEmpty() ? $active : collect($services);
        $access = $pool->where('state', 'access')->values();
        if ($access->count() === 1) {
            $this->checkInServiceId = (int) $access->first()['id'];

            return;
        }

        if ($access->count() > 1) {
            $this->checkInServiceId = (int) $access->first()['id'];

            return;
        }

        $rank = fn (?string $state): int => match ($state) {
            'overdue' => 5,
            'expired' => 4,
            'uses_exhausted' => 3,
            'no_access' => 2,
            'unpaid' => 1,
            'same_day_duplicate' => 1,
            default => 0,
        };

        $sorted = $pool->sortByDesc(fn (array $row): int => $rank($row['state'] ?? null))->values();
        $this->checkInServiceId = (int) $sorted->first()['id'];
    }

    

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

    

    private function checkInLocation(?QueueEntry $entry): ?int
    {
        if ($entry !== null) {
            return (int) $entry->location_id;
        }

        return LocationAccess::firstAccessibleLocationId(Auth::user());
    }

    

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
            'no_access' => __('app.reception.service_no_access'),
            'uses_exhausted' => __('app.reception.service_uses_exhausted', ['plan' => $subscription?->plan?->name ?? '']),
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
                __('app.reception.service_same_day_duplicate', ['plan' => $subscription->plan?->name ?? '']),
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
                'override_reason' => __('app.reception.service_same_day_duplicate', ['plan' => $subscription->plan?->name ?? '']),
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

    public function confirmSameDayDuplicateCheckInAndCount(): void
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
            app(PlanCheckInService::class)->checkIn(
                $member,
                $subscription,
                Auth::user(),
                true,
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
                'override' => false,
            ]);

            $this->broadcastCheckInResolution($entry, true, null);
        }

        $wasManualPostSignup = $this->pendingSignupCheckInQueueId !== null && $entry === null;

        $this->dispatch('notify',
            type: 'success',
            message: __('app.reception.checkin_approved', ['name' => $member->name]),
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

        
        try {
            $subscription = $row ? Subscription::find($row['subscription_id']) : null;

            app(PlanCheckInService::class)->checkInOverride(
                $member,
                $subscription,
                Auth::user(),
                __('app.reception.same_day_duplicate_denied_reason'),
                false,
                $this->checkInServiceId,
                $this->checkInLocation($entry),
            );
        } catch (\Throwable) {
            
        }

        $wasManualPostSignup = $this->pendingSignupCheckInQueueId !== null && $entry === null;

        if ($entry !== null) {
            $entry->update([
                'status' => 'denied',
                'denied_reason' => __('app.reception.same_day_duplicate_denied_reason'),
                'override' => true,
                'override_by_user_id' => Auth::id(),
                'override_reason' => __('app.reception.same_day_duplicate_denied_reason'),
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

    

    public function openAddPaymentModal(int $serviceId): void
    {
        $context = $this->pastDueModalContext($serviceId);

        if ($context === null) {
            return;
        }

        $this->dispatch('open-add-payment-modal', ...$context);
    }

    

    public function openChangeDueDateModal(int $serviceId): void
    {
        $context = $this->pastDueModalContext($serviceId);

        if ($context === null) {
            return;
        }

        $this->dispatch('open-change-due-date-modal', ...$context);
    }

    

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

    

    public function latestSubscriptionForService(Member $member, int $serviceId): ?Subscription
    {
        return $member->subscriptions()
            ->with('plan')
            ->whereHas('plan', fn ($query) => $query->forService($serviceId))
            ->orderByDesc('end_date')
            ->orderByDesc('id')
            ->first();
    }

    public function hasRenewableSubscriptionForService(Member $member, int $serviceId): bool
    {
        $subscription = $this->latestSubscriptionForService($member, $serviceId);

        if ($subscription === null) {
            return false;
        }

        return in_array($subscription->status?->value, [Status::Ongoing->value, Status::Expiring->value], true);
    }

    public function getStatusPillProperty(): ?array
    {
        if (! $this->selectedCheckInMemberId || ! $this->checkInServiceId) {
            return null;
        }

        $row = collect($this->checkInServices)->firstWhere('id', $this->checkInServiceId);

        if (! $row) {
            return null;
        }

        $member = Member::find($this->selectedCheckInMemberId);

        if (! $member || $member->checkInBlocker() === 'banned') {
            return null;
        }

        $state = $row['state'] ?? null;

        if ($state === 'access' || $state === null) {
            return null;
        }

        $subscription = ! empty($row['subscription_id']) ? Subscription::find((int) $row['subscription_id']) : null;

        if ($subscription) {
            $subscription->loadMissing('plan');
        }

        return match ($state) {
            'same_day_duplicate' => ['color' => 'warning', 'label' => __('app.reception.pill_already_checked_in')],
            'uses_exhausted' => $subscription?->plan?->name
                ? ['color' => 'warning', 'label' => __('app.reception.pill_no_uses', ['plan' => $subscription->plan->name])]
                : ['color' => 'warning', 'label' => __('app.reception.pill_no_uses_short')],
            'unpaid' => $this->statusPillForInvoice($member, $row, 'pill_payment_due', 'warning'),
            'overdue' => $this->statusPillForInvoice($member, $row, 'pill_payment_overdue', 'danger'),
            'expired' => ['color' => 'danger', 'label' => __('app.reception.pill_expired')],
            'no_access' => ['color' => 'danger', 'label' => __('app.reception.pill_no_access')],
            default => null,
        };
    }

    private function statusPillForInvoice(Member $member, array $row, string $key, string $color): ?array
    {
        $invoice = $this->pastDueInvoiceForMember($member, $row);

        if (! $invoice) {
            return null;
        }

        $date = \App\Support\Dates\DeviceDateFormat::format($invoice->due_date);

        return ['color' => $color, 'label' => __('app.reception.' . $key, ['date' => $date])];
    }

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

    

    protected function removeCheckInFromView(int $queueEntryId): void {}
}
