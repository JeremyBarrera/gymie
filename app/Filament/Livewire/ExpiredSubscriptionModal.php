<?php

namespace App\Filament\Livewire;

use App\Enums\Status;
use App\Helpers\Helpers;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Subscriptions\SubscriptionRenewalService;
use App\Support\Billing\PaymentMethod;
use App\Support\Notifications\FollowUpAlert;
use App\Contracts\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Expired-path renewal popup: opened on top of the check-in overlay for a
 * service row in the `expired` state, sells a new subscription through
 * SubscriptionRenewalService::renew() (never a hand-rolled
 * Subscription::create), then hands back to the host so the normal check-in
 * runs and overlay + popup close together.
 */
class ExpiredSubscriptionModal extends Component
{
    public ?int $memberId = null;

    public ?int $serviceId = null;

    public ?int $previousSubscriptionId = null;

    public ?int $planId = null;

    public string $paymentMethod = 'cash';

    /** Staff-picked dates — never defaulted to today. */
    public ?string $startDate = null;

    public ?string $endDate = null;

    public ?float $discountAmount = null;

    public ?float $paidAmount = null;

    #[On('open-expired-subscription-modal')]
    public function open(int $memberId, int $serviceId, int $previousSubscriptionId): void
    {
        $member = Member::find($memberId);
        $previous = Subscription::find($previousSubscriptionId);

        if (! $member || ! $previous || (int) $previous->member_id !== (int) $member->id) {
            return;
        }

        $this->resetForm();

        $this->memberId = (int) $member->id;
        $this->serviceId = (int) $serviceId;
        $this->previousSubscriptionId = (int) $previous->id;

        // UI-9 default: the expired subscription's own plan, falling back to
        // the first active plan of the service when that plan is gone.
        $defaultPlan = Plan::withTrashed()->find($previous->plan_id);
        $available = $this->planOptions;

        $this->planId = isset($available[$defaultPlan?->id])
            ? (int) $defaultPlan->id
            : (int) array_key_first($available);

        $this->paymentMethod = 'cash';
        $this->startDate = null;
        $this->endDate = null;
        $this->discountAmount = null;
        $this->paidAmount = null;

        $this->dispatch('open-modal', id: 'expired-subscription-modal');
    }

    /**
     * Active plans of the service available at the current tenant location.
     *
     * @return array<int, string>
     */
    public function getPlanOptionsProperty(): array
    {
        if ($this->serviceId === null) {
            return [];
        }

        $locationId = app(TenantContext::class)->locationId();

        return Plan::query()
            ->where('service_id', $this->serviceId)
            ->where('status', Status::Active)
            ->orderBy('name')
            ->get()
            ->filter(fn (Plan $plan): bool => $plan->availableAt($locationId))
            ->mapWithKeys(fn (Plan $plan): array => [
                (int) $plan->id => sprintf('%s · %s', $plan->name, Helpers::formatCurrency((float) $plan->amount)),
            ])
            ->all();
    }

    protected function rules(): array
    {
        return [
            'planId' => ['required', 'integer', 'in:'.implode(',', array_keys($this->planOptions))],
            'paymentMethod' => ['required', 'string', 'in:'.implode(',', array_keys(PaymentMethod::options()))],
            'startDate' => ['required', 'date'],
            'endDate' => ['nullable', 'date', 'after_or_equal:startDate'],
            'discountAmount' => ['nullable', 'numeric', 'min:0'],
            'paidAmount' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'planId' => __('app.fields.plan'),
            'paymentMethod' => __('app.fields.payment_method'),
            'startDate' => __('app.fields.start_date'),
            'endDate' => __('app.fields.end_date'),
            'discountAmount' => __('app.fields.discount'),
            'paidAmount' => __('app.fields.paid_amount'),
        ];
    }

    public function submit(): void
    {
        $actor = Auth::user();

        if (! $actor || ! $actor->can('create', Subscription::class)) {
            $this->notifyDanger(__('app.reception.verify_sale_permission_denied'));

            return;
        }

        $this->validate();

        $member = Member::find($this->memberId);
        $previous = Subscription::find($this->previousSubscriptionId);

        if (! $member || ! $previous || (int) $previous->member_id !== (int) $member->id) {
            $this->notifyDanger(__('app.notifications.check_in_failed'));

            return;
        }

        try {
            // Lock + re-check inside a transaction so a double submit can't
            // chain two renewals off the same expired subscription; renew()
            // runs its own transaction for the multi-table write itself.
            /** @var array{subscription: Subscription, invoice: Invoice} $result */
            $result = DB::transaction(function () use ($previous, $member): array {
                $freshPrevious = Subscription::query()
                    ->whereKey($previous->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($freshPrevious->status === Status::Renewed) {
                    throw new \RuntimeException('already renewed');
                }

                if ((int) $freshPrevious->member_id !== (int) $member->id) {
                    throw new \RuntimeException('subscription mismatch');
                }

                return app(SubscriptionRenewalService::class)->renew($freshPrevious, [
                    'plan_id' => (int) $this->planId,
                    'start_date' => (string) $this->startDate,
                    'end_date' => filled($this->endDate) ? (string) $this->endDate : null,
                    'invoice' => [
                        'payment_method' => $this->paymentMethod,
                        'discount_amount' => (float) ($this->discountAmount ?? 0),
                        'paid_amount' => (float) ($this->paidAmount ?? 0),
                        'date' => (string) $this->startDate,
                    ],
                ]);
            });

            $subscription = $result['subscription'];
            $invoice = $result['invoice'];
        } catch (\Throwable $exception) {
            Log::error('Expired-path renewal failed', [
                'member_id' => $member->id,
                'previous_subscription_id' => $previous->id,
                'error' => $exception->getMessage(),
            ]);

            $this->notifyDanger(__('app.notifications.check_in_failed'));

            return;
        }

        if ((float) $invoice->due_amount > 0) {
            FollowUpAlert::send(
                action: 'new_subscription',
                member: $member,
                actor: $actor,
                reason: __('app.check_in.balance_remaining', ['amount' => Helpers::formatCurrency((float) $invoice->due_amount)]),
                subscription: $subscription,
                invoice: $invoice,
            );
        }

        $subscriptionId = (int) $subscription->id;

        $this->dispatch('close-modal', id: 'expired-subscription-modal');

        $this->resetForm();

        $this->dispatch('check-in.resolved-by-modal', subscriptionId: $subscriptionId);
    }

    public function cancel(): void
    {
        $this->resetForm();

        $this->dispatch('close-modal', id: 'expired-subscription-modal');
    }

    private function resetForm(): void
    {
        $this->memberId = null;
        $this->serviceId = null;
        $this->previousSubscriptionId = null;
        $this->planId = null;
        $this->paymentMethod = 'cash';
        $this->startDate = null;
        $this->endDate = null;
        $this->discountAmount = null;
        $this->paidAmount = null;

        $this->resetErrorBag();
    }

    private function notifyDanger(string $message): void
    {
        $this->dispatch('notify', type: 'danger', message: $message);
    }

    public function render(): View
    {
        return view('livewire.expired-subscription-modal');
    }
}
