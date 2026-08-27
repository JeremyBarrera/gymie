<?php

namespace App\Filament\Concerns;

use App\Events\QueueEntryResolved;
use App\Filament\Resources\Subscriptions\Schemas\SubscriptionForm;
use App\Helpers\Helpers;
use App\Models\Location;
use App\Models\LocationToken;
use App\Models\Member;
use App\Models\Plan;
use App\Models\QueueEntry;
use App\Models\Service;
use App\Models\Subscription;
use App\Services\Members\MemberApplicationService;
use App\Services\Membership\PlanCheckInService;
use App\Support\Billing\InvoiceCalculator;
use App\Support\Billing\PaymentMethod;
use App\Support\Locations\LocationAccess;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Shared sign-up verification flow used by the Reception page and the
 * global LiveSignupPopup component.
 *
 * Hosts must declare the public `$selectedQueueEntryId` property.
 */
trait HandlesSignupVerification
{
    public bool $showVerifyOverlay = false;

    public int $verifyStep = 1;

    /** @var array<string, mixed> */
    public array $verifyForm = [];

    public ?string $verifyPhoto = null;

    /** @var array<int> Sign-up entries that arrived while the overlay was already open. */
    public array $popupQueue = [];

    public bool $verifyCheckIn = false;

    public ?int $verifyCheckInServiceId = null;

    /** Temporary hold for the member created during confirmSignup — used by step 4. */
    public ?Member $verifyCreatedMember = null;

    public function getVerifyPlansProperty(): array
    {
        return Plan::query()
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Plan $plan): array => [
                $plan->id => SubscriptionForm::formatPlanOptionLabel($plan),
            ])
            ->all();
    }

    public function getVerifyPaymentMethodsProperty(): array
    {
        return PaymentMethod::options();
    }

    /**
     * Per-service states for the just-created member (step 4).
     * Every service of the sold plan shows `access` — others show `no_access`.
     *
     * @return array<int, array{id: int, name: string, state: string, subscription_id: int|null, warning: string|null}>
     */
    public function getVerifyCheckInServicesProperty(): array
    {
        if (! $this->verifyCreatedMember) {
            return [];
        }

        $planId = is_numeric($this->verifyForm['sale']['plan_id'] ?? null)
            ? (int) $this->verifyForm['sale']['plan_id']
            : null;

        if ($planId === null) {
            return [];
        }

        $plan = Plan::query()->with('services')->find($planId);

        if (! $plan || $plan->services->isEmpty()) {
            return [];
        }

        $subscription = $this->verifyCreatedMember->subscriptions()
            ->where('plan_id', $planId)
            ->first();

        if (! $subscription) {
            return [];
        }

        return $plan->services
            ->sortBy('name')
            ->values()
            ->map(fn (Service $service): array => [
                'id' => (int) $service->id,
                'name' => (string) $service->name,
                'state' => 'access',
                'subscription_id' => $subscription->id,
                'warning' => null,
            ])
            ->all();
    }

    public function getLocationTokens(): array
    {
        $query = LocationToken::where('tokenable_type', Location::class)
            ->whereIn('kind', ['checkin', 'signup']);

        $accessible = LocationAccess::accessibleLocationIds(Auth::user());

        if ($accessible !== null) {
            $query->whereIn('tokenable_id', $accessible);
        }

        return $query->pluck('token')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Open the verify overlay for a live sign-up entry, or queue it when the
     * overlay is already showing another entry.
     */
    public function queueOrOpenSignup(QueueEntry $entry): void
    {
        if (! in_array($entry->status, ['waiting', 'attending'], true)) {
            return;
        }

        if ($this->showVerifyOverlay) {
            $this->popupQueue[] = $entry->id;
        } else {
            $this->openVerifyOverlay($entry->id);
        }
    }

    public function openVerifyOverlay(int $queueEntryId): void
    {
        $entry = QueueEntry::find($queueEntryId);
        if (! $entry || $entry->kind !== 'signup') {
            return;
        }

        $this->selectedQueueEntryId = $entry->id;
        $this->showVerifyOverlay = true;
        $this->dispatch('open-modal', id: 'verify-overlay');
        $this->verifyStep = 1;
        $this->verifyPhoto = null;
        $this->verifyCheckIn = false;
        $this->verifyCheckInServiceId = null;
        $this->verifyCreatedMember = null;
        $this->verifyForm = array_intersect_key($entry->payload ?? [], array_flip([
            'name', 'contact', 'government_id', 'email', 'gender', 'dob',
            'emergency_contact', 'health_issue', 'goal',
        ]));
        $this->verifyForm['sale'] = $this->defaultSale();

        // Pre-select the first available plan so the dropdown is never empty.
        $plans = $this->verifyPlans;
        if (! empty($plans)) {
            $firstPlanId = array_key_first($plans);
            $this->verifyForm['sale']['plan_id'] = (int) $firstPlanId;
            $this->recalculateVerifySale();
        }

        foreach (['contact', 'emergency_contact'] as $phoneField) {
            $raw = $this->verifyForm[$phoneField] ?? null;

            if (filled($raw)) {
                [$dialCode, $local] = Helpers::parsePhoneField($raw);
                $this->verifyForm[$phoneField] = $local;

                // Only prefixed values carry a real dial code; for unprefixed
                // (legacy) data the placeholder is a display default, not a
                // stored prefix, so it must not be re-combined on save.
                if (str_starts_with(trim((string) $raw), '+')) {
                    $this->verifyForm[$phoneField.'_dial_code'] = $dialCode;
                }
            }
        }

        $this->popupQueue = array_values(array_diff($this->popupQueue, [$entry->id]));
    }

    public function closeVerifyOverlay(): void
    {
        $closedId = (int) $this->selectedQueueEntryId;
        $memberCreated = $this->verifyStep === 4 && $this->verifyCreatedMember !== null;

        $this->resetVerifyOverlay();
        $this->openNextFromPopupQueue();

        // Step 4 only renders after the member was persisted, so closing
        // there resolves the sign-up instead of leaving it in the queue.
        if ($memberCreated && $closedId > 0) {
            $this->removeApprovedSignupFromView($closedId);
        }
    }

    private function resetVerifyOverlay(): void
    {
        // Close through Filament's modal manager BEFORE the state clear can
        // morph the modal out of the DOM — an unmount while open leaves a
        // stuck semi-transparent window stacked on top of the next modal.
        $this->dispatch('close-modal', id: 'verify-overlay');

        $closedId = (int) $this->selectedQueueEntryId;

        $this->showVerifyOverlay = false;
        $this->selectedQueueEntryId = null;
        $this->verifyStep = 1;
        $this->verifyPhoto = null;
        $this->verifyForm = [];
        $this->verifyCheckIn = false;
        $this->verifyCheckInServiceId = null;
        $this->verifyCreatedMember = null;
        $this->dispatch('verify-overlay-closed');

        if ($closedId > 0) {
            $this->verifyOverlayClosed($closedId);
        }
    }

    private function openNextFromPopupQueue(): void
    {
        $nextId = array_shift($this->popupQueue);
        if ($nextId !== null) {
            $this->openVerifyOverlay((int) $nextId);
        }
    }

    /**
     * Hook for hosts that track sign-ups closed without being attended.
     */
    protected function verifyOverlayClosed(int $queueEntryId): void {}

    public function verifyContinue(): void
    {
        if ($this->verifyStep === 1) {
            $this->verifyStep = 2;
            $this->dispatch('verify-photo-step');
        } elseif ($this->verifyStep === 2) {
            $this->verifyStep = 3;
        }
    }

    public function verifyBack(): void
    {
        if ($this->verifyStep === 3) {
            $this->verifyStep = 2;

            return;
        }

        $this->verifyStep = 1;
        $this->dispatch('verify-overlay-closed');
    }

    /**
     * Recompute the step-3 sale summary whenever a sale field changes.
     */
    public function updatedVerifyForm(mixed $value, string $key): void
    {
        if (str_starts_with($key, 'sale.')) {
            $this->recalculateVerifySale();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultSale(): array
    {
        return [
            'plan_id' => null,
            'quantity' => 1,
            'start_date' => now()->toDateString(),
            'end_date' => null,
            'payment_method' => 'cash',
            'discount_amount' => 0,
            'paid_amount' => 0,
            'fee' => 0,
            'tax' => 0,
            'total' => 0,
            'due' => 0,
        ];
    }

    private function recalculateVerifySale(): void
    {
        $sale = $this->verifyForm['sale'] ?? [];

        $planId = is_numeric($sale['plan_id'] ?? null) ? (int) $sale['plan_id'] : null;
        $startDate = (string) ($sale['start_date'] ?? '');
        $quantity = max(1, (int) ($sale['quantity'] ?? 1));
        $plan = $planId !== null ? Plan::find($planId) : null;

        $fee = $plan ? (float) $plan->amount * $quantity : 0.0;
        $endDate = ($plan && $startDate)
            ? Helpers::calculateSubscriptionEndDate($startDate, $planId, $quantity)
            : null;

        $discount = min(max((float) ($sale['discount_amount'] ?? 0), 0), $fee);
        $paid = (float) ($sale['paid_amount'] ?? 0);

        $summary = InvoiceCalculator::summary($fee, Helpers::getTaxRate() ?: 0, $discount, $paid);

        $this->verifyForm['sale'] = array_merge($sale, [
            'end_date' => $endDate,
            'fee' => $summary['fee'],
            'tax' => $summary['tax'],
            'total' => $summary['total'],
            'due' => $summary['due'],
        ]);
    }

    public function confirmSignup(): void
    {
        if (! $this->selectedQueueEntryId) {
            return;
        }

        if (! Auth::user()->can('create', Member::class)) {
            $this->dispatch('notify',
                type: 'danger',
                message: __('app.reception.verify_sale_permission_denied'),
            );

            return;
        }

        $entry = QueueEntry::find($this->selectedQueueEntryId);
        if (! $entry || $entry->kind !== 'signup') {
            $this->closeVerifyOverlay();

            return;
        }

        $form = $this->verifyForm;

        if (filled($form['contact'] ?? null) && filled($form['contact_dial_code'] ?? null)) {
            $form['contact'] = Helpers::combinePhoneField($form['contact_dial_code'], $form['contact']);
        }

        if (filled($form['emergency_contact'] ?? null) && filled($form['emergency_contact_dial_code'] ?? null)) {
            $form['emergency_contact'] = Helpers::combinePhoneField($form['emergency_contact_dial_code'], $form['emergency_contact']);
        }

        unset($form['contact_dial_code'], $form['emergency_contact_dial_code']);
        unset($form['sale']);

        try {
            $member = app(MemberApplicationService::class)->approveSignup(
                $entry,
                $form,
                $this->verifyPhoto,
                $this->buildSale(),
                Auth::user(),
            );
        } catch (ValidationException $exception) {
            $this->dispatch('notify',
                type: 'danger',
                message: collect($exception->errors())->flatten()->first()
                    ?: __('app.reception.verify_failed'),
            );

            return;
        } catch (InvalidArgumentException $exception) {
            $this->dispatch('notify',
                type: 'danger',
                message: $exception->getMessage() ?: __('app.reception.verify_failed'),
            );

            return;
        }

        $locationToken = LocationToken::where('tokenable_type', Location::class)
            ->where('tokenable_id', $entry->location_id)
            ->where('kind', 'signup')
            ->value('token');

        if ($locationToken) {
            broadcast(new QueueEntryResolved(
                $entry->id,
                $entry->uuid,
                $locationToken,
                'signup',
                $entry->payload,
                true,
                null
            ))->toOthers();
        }

        $this->dispatch('notify',
            type: 'success',
            message: __('app.reception.verify_saved', ['name' => $member->name]),
        );

        if ($this->verifyCheckIn) {
            $memberCreated = $member;
            $this->resetVerifyOverlay();
            $this->beginManualCheckIn(new Collection([$memberCreated]));

            return;
        }

        $this->resetVerifyOverlay();
        $this->removeApprovedSignupFromView($entry->id);
    }

    /**
     * Execute the optional post-signup check-in (step 4).
     */
    public function confirmVerifyCheckIn(): void
    {
        if (! $this->verifyCreatedMember || ! $this->verifyCheckInServiceId) {
            $this->resetVerifyOverlay();

            return;
        }

        $entry = QueueEntry::find($this->selectedQueueEntryId);

        $serviceId = $this->verifyCheckInServiceId;
        $subscription = $this->verifyCreatedMember->subscriptions()
            ->with('plan.services')
            ->get()
            ->first(fn (Subscription $sub): bool => (bool) $sub->plan?->services?->contains('id', $serviceId));

        if (! $subscription) {
            $this->dispatch('notify',
                type: 'warning',
                message: __('app.reception.checkin_failed_member_created'),
            );
            $this->closeVerifyOverlay();

            return;
        }

        try {
            app(PlanCheckInService::class)->checkIn(
                $this->verifyCreatedMember,
                $subscription,
                Auth::user(),
            );
        } catch (\Throwable) {
            $this->dispatch('notify',
                type: 'warning',
                message: __('app.reception.checkin_failed_member_created'),
            );
            $this->closeVerifyOverlay();

            return;
        }

        if ($entry) {
            $locationToken = LocationToken::where('tokenable_type', Location::class)
                ->where('tokenable_id', $entry->location_id)
                ->where('kind', 'signup')
                ->value('token');

            if ($locationToken) {
                broadcast(new QueueEntryResolved(
                    $entry->id,
                    $entry->uuid,
                    $locationToken,
                    'signup',
                    $entry->payload,
                    true,
                    null,
                    true,
                ))->toOthers();
            }
        }

        $this->dispatch('notify',
            type: 'success',
            message: __('app.reception.checkin_success'),
        );

        $closedId = $entry?->id;
        $this->resetVerifyOverlay();
        if ($closedId) {
            $this->removeApprovedSignupFromView($closedId);
        }
    }

    /**
     * Build the mandatory first-sale payload from the step-3 fields. The
     * invoice date/due date default to today; the invoice number is
     * generated by the Invoice model on save.
     *
     * @return array<string, mixed>
     */
    private function buildSale(): array
    {
        $sale = $this->verifyForm['sale'] ?? [];

        if (! is_numeric($sale['plan_id'] ?? null)) {
            throw new InvalidArgumentException(__('app.reception.verify_sale_required'));
        }

        $today = now()->toDateString();

        return [
            'plan_id' => (int) $sale['plan_id'],
            'quantity' => max(1, (int) ($sale['quantity'] ?? 1)),
            'start_date' => (string) ($sale['start_date'] ?? $today),
            'end_date' => $sale['end_date'] ?: null,
            'invoices' => [[
                'date' => $today,
                'due_date' => $today,
                'payment_method' => (string) ($sale['payment_method'] ?? 'cash'),
                'discount' => 0,
                'discount_amount' => (float) ($sale['discount_amount'] ?? 0),
                'discount_note' => null,
                'paid_amount' => (float) ($sale['paid_amount'] ?? 0),
            ]],
        ];
    }

    /**
     * Hook for hosts that render a sign-up queue (e.g. the Reception page).
     */
    protected function removeApprovedSignupFromView(int $queueEntryId): void {}

    /**
     * Close the verify overlay when the entry it shows was handled or expired
     * by another tab / device, so every tab stays in sync.
     */
    public function closeVerifyIfStale(int $queueEntryId, bool $notifyHandledElsewhere): void
    {
        if (! $this->showVerifyOverlay || (int) $this->selectedQueueEntryId !== $queueEntryId) {
            return;
        }

        $this->closeVerifyOverlay();

        if ($notifyHandledElsewhere) {
            $this->dispatch('notify',
                type: 'warning',
                message: __('app.reception.handled_elsewhere'),
            );
        }
    }
}
