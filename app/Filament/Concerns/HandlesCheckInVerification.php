<?php

namespace App\Filament\Concerns;

use App\Events\QueueEntryResolved;
use App\Exceptions\PlanCheckIn\OverdueInvoiceException;
use App\Models\Location;
use App\Models\LocationToken;
use App\Models\Member;
use App\Models\QueueEntry;
use App\Models\Subscription;
use App\Models\Invoice;
use App\Notifications\ReceptionOverrideNotification;
use App\Services\Membership\PlanCheckInService;
use App\Support\DevOps\FeatureFlags;
use App\Support\Notifications\NotificationRecipients;
use Illuminate\Support\Facades\Auth;

/**
 * Shared check-in verification flow used by the Reception page and the
 * global LiveSignupPopup component: a live popup opens when a check-in
 * queue entry arrives, shows the matching profile (or a candidate picker
 * when the identifier matches more than one member), and lets staff
 * approve (picking the service the member checks in against) or deny the
 * check-in. Non-access services can be overridden after confirmation.
 */
trait HandlesCheckInVerification
{
    public bool $showCheckInOverlay = false;

    public ?int $selectedCheckInEntryId = null;

    /** The member chosen for the entry — auto-resolved for single matches. */
    public ?int $selectedCheckInMemberId = null;

    /** The service (from the location's services) the member checks in against. */
    public ?int $checkInServiceId = null;

    public bool $checkInDenyStep = false;

    public string $checkInDenyReason = '';

    /** Override confirm step: which recipients will be notified + optional reason. */
    public bool $checkInOverrideStep = false;

    public string $checkInOverrideReason = '';

    /** @var array<string> Recipient names shown on the override confirm step. */
    public array $checkInOverrideRecipients = [];

    /** Overdue due-date-change step: staff must extend the invoice due date before override. */
    public bool $checkInOverrideDueDateStep = false;

    public ?int $checkInOverrideInvoiceId = null;

    public ?string $checkInOverrideNewDueDate = null;

    /** When true, confirmCheckInOverride skips the overdue-invoice gate (used after due-date change). */
    public bool $checkInOverrideSkipOverdue = false;

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

        $this->checkInPopupQueue = array_values(array_diff($this->checkInPopupQueue, [$entry->id]));

        if ($this->selectedCheckInMemberId) {
            $services = $this->checkInServices;
            if (! empty($services)) {
                $access = collect($services)->where('state', 'access')->values();
                if ($access->count() === 1) {
                    $this->checkInServiceId = (int) $access->first()['id'];
                } else {
                    $this->checkInServiceId = (int) $services[0]['id'];
                }
            }
        }
    }

    public function closeCheckInOverlay(): void
    {
        $this->resetCheckInOverlay();
        $this->openNextCheckInFromQueue();
    }

    private function resetCheckInOverlay(): void
    {
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
        $this->checkInOverrideDueDateStep = false;
        $this->checkInOverrideInvoiceId = null;
        $this->checkInOverrideNewDueDate = null;
        $this->checkInOverrideSkipOverdue = false;

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
     * Resolve an ambiguous identifier: pick the correct profile among the
     * candidates carried by the entry payload.
     */
    public function selectCheckInMember(int $memberId): void
    {
        if (! $this->selectedCheckInEntryId) {
            return;
        }

        $entry = QueueEntry::find($this->selectedCheckInEntryId);
        $candidateIds = array_map('intval', $entry?->payload['candidate_member_ids'] ?? []);

        if (! in_array($memberId, $candidateIds, true)) {
            return;
        }

        $this->selectedCheckInMemberId = $memberId;
        $this->checkInServiceId = null;
        $this->checkInOverrideStep = false;

        $services = $this->checkInServices;
        if (! empty($services)) {
            $access = collect($services)->where('state', 'access')->values();
            if ($access->count() === 1) {
                $this->checkInServiceId = (int) $access->first()['id'];
            } else {
                $this->checkInServiceId = (int) $services[0]['id'];
            }
        }
    }

    /**
     * Per-service check-in states for the selected member at the entry's
     * location (see `PlanCheckInService::serviceStatesForMember()`).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCheckInServicesProperty(): array
    {
        if (! $this->selectedCheckInMemberId || ! $this->selectedCheckInEntryId) {
            return [];
        }

        $entry = QueueEntry::find($this->selectedCheckInEntryId);
        $member = Member::find($this->selectedCheckInMemberId);

        if (! $entry || ! $member) {
            return [];
        }

        return app(PlanCheckInService::class)->serviceStatesForMember($member, (int) $entry->location_id);
    }

    public function updatedCheckInServiceId($value): void
    {
        if (blank($value)) {
            $this->checkInServiceId = null;
            $this->checkInOverrideStep = false;

            return;
        }

        $serviceId = (int) $value;
        $valid = collect($this->checkInServices)
            ->contains(fn (array $row): bool => (int) $row['id'] === $serviceId);

        if (! $valid) {
            $this->checkInServiceId = null;
        }

        $this->checkInOverrideStep = false;
    }

    public function selectCheckInService(int $serviceId): void
    {
        if (! $this->selectedCheckInMemberId) {
            return;
        }

        $valid = collect($this->checkInServices)
            ->contains(fn (array $row): bool => (int) $row['id'] === $serviceId);

        if (! $valid) {
            return;
        }

        $this->checkInServiceId = $serviceId;
        $this->checkInOverrideStep = false;
    }

    public function approveCheckIn(): void
    {
        if (! $this->selectedCheckInEntryId || ! $this->selectedCheckInMemberId || ! $this->checkInServiceId) {
            return;
        }

        $entry = QueueEntry::find($this->selectedCheckInEntryId);
        if (! $entry || $entry->kind !== 'checkin') {
            $this->closeCheckInOverlay();

            return;
        }

        if (! in_array($entry->status, ['waiting', 'attending'], true)) {
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
            app(PlanCheckInService::class)->checkIn($member, $subscription, Auth::user(), false);
        } catch (\Throwable $exception) {
            $this->dispatch('notify',
                type: 'danger',
                message: $exception->getMessage(),
            );

            return;
        }

        $entry->update(['status' => 'approved', 'override' => false]);

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
                true,
                null
            ))->toOthers();
        }

        $this->dispatch('notify',
            type: 'success',
            message: __('app.reception.checkin_approved', ['name' => $member->name]),
        );

        $this->resetCheckInOverlay();
        $this->removeCheckInFromView($entry->id);
    }

    /**
     * Move the overlay to the override confirm step for the given service:
     * shows who will be notified, with an optional reason.
     */
    public function openCheckInOverrideFor(int $serviceId): void
    {
        $this->selectCheckInService($serviceId);

        if ($this->checkInServiceId !== $serviceId) {
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
        if (! $this->selectedCheckInEntryId || ! $this->selectedCheckInMemberId || ! $this->checkInServiceId) {
            return;
        }

        if (! FeatureFlags::activeForUser(Auth::user(), 'checkin.override')) {
            $this->dispatch('notify',
                type: 'danger',
                message: __('app.reception.override_disabled'),
            );

            return;
        }

        $entry = QueueEntry::find($this->selectedCheckInEntryId);
        if (! $entry || $entry->kind !== 'checkin' || ! in_array($entry->status, ['waiting', 'attending'], true)) {
            $this->closeCheckInOverlay();

            return;
        }

        $row = collect($this->checkInServices)->firstWhere('id', $this->checkInServiceId);
        $member = Member::find($this->selectedCheckInMemberId);
        $subscription = $row ? Subscription::find($row['subscription_id']) : null;
        $overrideReason = match ($row['state'] ?? null) {
            'expired' => 'expired',
            'no_access' => 'no_subscription',
            default => null,
        };

        if (! $member) {
            return;
        }

        try {
            app(PlanCheckInService::class)->checkInOverride(
                $member,
                $subscription,
                Auth::user(),
                $this->checkInOverrideReason ?: $overrideReason,
                $this->checkInOverrideSkipOverdue,
                $this->checkInServiceId,
            );
        } catch (OverdueInvoiceException $exception) {
            if ($this->checkInOverrideSkipOverdue) {
                $this->dispatch('notify',
                    type: 'danger',
                    message: $exception->getMessage(),
                );

                return;
            }

            $overdueInvoice = $subscription->invoices()
                ->where(fn ($q) => $q->where('status', 'issued')->orWhere('status', 'partial')->orWhere('status', 'overdue'))
                ->where('due_amount', '>', 0)
                ->whereNotNull('due_date')
                ->where('due_date', '<', now(config('app.timezone') ?: 'UTC'))
                ->orderBy('due_date')
                ->first();

            if (! $overdueInvoice) {
                $this->dispatch('notify',
                    type: 'danger',
                    message: __('app.reception.override_must_change_date'),
                );

                return;
            }

            $this->checkInOverrideInvoiceId = $overdueInvoice->id;
            $this->checkInOverrideNewDueDate = now(config('app.timezone') ?: 'UTC')->addWeek()->format('Y-m-d');
            $this->checkInOverrideDueDateStep = true;

            $this->dispatch('notify',
                type: 'danger',
                message: __('app.reception.override_must_change_date'),
            );

            return;
        } catch (\Throwable $exception) {
            $this->dispatch('notify',
                type: 'danger',
                message: $exception->getMessage(),
            );

            return;
        }

        $entry->update([
            'status' => 'approved',
            'override' => true,
            'override_by_user_id' => Auth::id(),
            'override_reason' => $this->checkInOverrideReason ?: null,
        ]);

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
                true,
                null
            ))->toOthers();
        }

        foreach (NotificationRecipients::resolve('override') as $user) {
            $user->notify(new ReceptionOverrideNotification($entry, $member, Auth::user(), $overrideReason));
        }

        $this->dispatch('notify',
            type: 'warning',
            message: __('app.reception.override_approved'),
        );

        $this->resetCheckInOverlay();
        $this->removeCheckInFromView($entry->id);
    }

    /**
     * Update the overdue invoice's due date and retry the override.
     */
    public function confirmDueDateChange(): void
    {
        if (! $this->checkInOverrideInvoiceId || ! $this->checkInOverrideNewDueDate) {
            return;
        }

        $invoice = Invoice::find($this->checkInOverrideInvoiceId);
        if (! $invoice) {
            $this->dispatch('notify',
                type: 'danger',
                message: __('app.reception.invoice_not_found'),
            );

            return;
        }

        $newDueDate = \Carbon\Carbon::parse($this->checkInOverrideNewDueDate);
        $invoice->update([
            'due_date' => $newDueDate,
        ]);

        $this->checkInOverrideDueDateStep = false;
        $this->checkInOverrideInvoiceId = null;
        $this->checkInOverrideNewDueDate = null;
        $this->checkInOverrideSkipOverdue = true;

        $this->confirmCheckInOverride();
    }

    /**
     * Go back from the due-date step to the override confirm step.
     */
    public function cancelDueDateChange(): void
    {
        $this->checkInOverrideDueDateStep = false;
        $this->checkInOverrideInvoiceId = null;
        $this->checkInOverrideNewDueDate = null;
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
        if (! $this->selectedCheckInEntryId) {
            return;
        }

        $entry = QueueEntry::find($this->selectedCheckInEntryId);
        if (! $entry || $entry->kind !== 'checkin') {
            $this->closeCheckInOverlay();

            return;
        }

        if (! in_array($entry->status, ['waiting', 'attending'], true)) {
            $this->closeCheckInOverlay();

            return;
        }

        $reason = $this->checkInDenyReason ?: __('app.reception.denied_no_reason');

        $entry->update([
            'status' => 'denied',
            'denied_reason' => $reason,
            'override' => false,
        ]);

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
                false,
                $reason
            ))->toOthers();
        }

        $this->dispatch('notify', type: 'success', message: __('app.reception.denied'));

        $this->resetCheckInOverlay();
        $this->removeCheckInFromView($entry->id);
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
