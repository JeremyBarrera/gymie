<?php

namespace App\Filament\Livewire;

use App\Helpers\Helpers;
use App\Models\Invoice;
use App\Models\Member;
use App\Models\Subscription;
use App\Support\AppConfig;
use App\Support\Billing\PaymentMethod;
use App\Support\Notifications\FollowUpAlert;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Past-due "Add payment" popup: records a payment against the blocking
 * invoice inside a transaction, then hands back to the host — paid in full
 * triggers a normal check-in, a partial balance requires the next payment
 * due date and completes an override-semantics check-in plus a
 * `payment_added` follow-up alert.
 */
class AddPaymentModal extends Component
{
    public ?int $memberId = null;

    public ?int $invoiceId = null;

    public ?int $serviceId = null;

    public ?int $subscriptionId = null;

    /** Defaults to the full balance; staff may lower it for a partial payment. */
    public ?float $amount = null;

    public string $paymentMethod = 'cash';

    /** Required while a balance remains after this payment. */
    public ?string $nextDueDate = null;

    public string $reason = '';

    #[On('open-add-payment-modal')]
    public function open(int $memberId, int $invoiceId, int $serviceId, ?int $subscriptionId = null): void
    {
        $member = Member::find($memberId);
        $invoice = Invoice::find($invoiceId);

        if (! $member || ! $invoice || (float) $invoice->due_amount <= 0) {
            return;
        }

        if ((int) ($invoice->subscription?->member_id ?? 0) !== (int) $member->id) {
            return;
        }

        $this->resetForm();

        $this->memberId = (int) $member->id;
        $this->invoiceId = (int) $invoice->id;
        $this->serviceId = (int) $serviceId;
        $this->subscriptionId = $subscriptionId !== null ? (int) $subscriptionId : null;

        // UI-9 default: settling in full is the most likely choice.
        $this->amount = (float) $invoice->due_amount;
        $this->paymentMethod = 'cash';
        // Never defaults to today — staff must consciously pick it.
        $this->nextDueDate = null;
        $this->reason = '';

        $this->dispatch('open-modal', id: 'add-payment-modal');
    }

    public function getInvoiceProperty(): ?Invoice
    {
        return $this->invoiceId !== null ? Invoice::find($this->invoiceId) : null;
    }

    /**
     * Live balance line: what remains on the invoice after the entered amount.
     */
    public function getProjectedRemainingProperty(): float
    {
        $due = (float) ($this->invoice?->due_amount ?? 0);

        return max(round($due - max((float) ($this->amount ?? 0), 0), 2), 0.0);
    }

    protected function rules(): array
    {
        $due = (float) ($this->invoice?->due_amount ?? 0);
        $isPartial = round($due - max((float) ($this->amount ?? 0), 0), 2) > 0;

        return [
            'amount' => ['required', 'numeric', 'min:0.01', 'max:'.max($due, 0)],
            'paymentMethod' => ['required', 'string', 'in:'.implode(',', array_keys(PaymentMethod::options()))],
            'nextDueDate' => [
                $isPartial ? 'required' : 'nullable',
                'date',
                'after:today',
            ],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'amount' => __('app.fields.amount'),
            'paymentMethod' => __('app.fields.payment_method'),
            'nextDueDate' => __('app.check_in.next_payment_due'),
            'reason' => __('app.check_in.override_message_optional'),
        ];
    }

    protected function messages(): array
    {
        return [
            'nextDueDate.required' => __('app.check_in.next_payment_due_required'),
        ];
    }

    public function submit(): void
    {
        $actor = Auth::user();
        $invoice = $this->invoice;

        if (! $actor || ! $invoice || ! $actor->can('update', $invoice)) {
            $this->notifyDanger(__('app.notifications.check_in_failed'));

            return;
        }

        $this->validate();

        $member = Member::find($this->memberId);

        if (! $member || (int) ($invoice->subscription?->member_id ?? 0) !== (int) $member->id) {
            $this->notifyDanger(__('app.notifications.check_in_failed'));

            return;
        }

        $payingFull = $this->projectedRemaining <= 0;

        try {
            DB::transaction(function () use ($invoice, $actor, $payingFull): void {
                $invoice->transactions()->create([
                    'type' => 'payment',
                    'amount' => min(max((float) $this->amount, 0), (float) $invoice->due_amount),
                    'occurred_at' => now(AppConfig::timezone()),
                    'payment_method' => $this->paymentMethod,
                    'note' => filled($this->reason) ? $this->reason : null,
                    'created_by' => $actor->id,
                ]);

                $invoice->refresh();

                if (! $payingFull) {
                    $invoice->update([
                        'due_date' => Carbon::parse((string) $this->nextDueDate)->toDateString(),
                    ]);
                }
            });
        } catch (\Throwable $exception) {
            Log::error('Check-in payment recording failed', [
                'member_id' => $member->id,
                'invoice_id' => $invoice->id,
                'error' => $exception->getMessage(),
            ]);

            $this->notifyDanger(__('app.notifications.invalid_payment_amount'));

            return;
        }

        $invoice->refresh();

        $remaining = (float) $invoice->due_amount;
        $balanceText = __('app.check_in.balance_remaining', ['amount' => Helpers::formatCurrency($remaining)]);
        $serviceId = (int) $this->serviceId;
        $subscriptionId = $this->subscriptionId !== null ? (int) $this->subscriptionId : 0;
        $typedReason = filled($this->reason) ? $this->reason : null;

        // Any subscription left not fully paid alerts the follow-up owners.
        if ($remaining > 0) {
            FollowUpAlert::send(
                action: 'payment_added',
                member: $member,
                actor: $actor,
                reason: $balanceText,
                subscription: $invoice->subscription,
                invoice: $invoice,
            );
        }

        $this->dispatch('close-modal', id: 'add-payment-modal');

        $this->resetForm();

        if ($remaining > 0) {
            $checkInReason = $typedReason ?? trim($balanceText.' · '.__('app.check_in.next_payment_due').': '.$invoice->due_date->format('Y-m-d'));

            $this->dispatch('check-in.assisted-override',
                serviceId: $serviceId,
                reason: $checkInReason,
            );
        } else {
            $this->dispatch('check-in.resolved-by-modal', subscriptionId: $subscriptionId);
        }
    }

    public function cancel(): void
    {
        $this->resetForm();

        $this->dispatch('close-modal', id: 'add-payment-modal');
    }

    private function resetForm(): void
    {
        $this->memberId = null;
        $this->invoiceId = null;
        $this->serviceId = null;
        $this->subscriptionId = null;
        $this->amount = null;
        $this->paymentMethod = 'cash';
        $this->nextDueDate = null;
        $this->reason = '';

        $this->resetErrorBag();
    }

    private function notifyDanger(string $message): void
    {
        $this->dispatch('notify', type: 'danger', message: $message);
    }

    public function render(): View
    {
        return view('livewire.add-payment-modal');
    }
}
