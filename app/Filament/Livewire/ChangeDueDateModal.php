<?php

namespace App\Filament\Livewire;

use App\Models\Invoice;
use App\Models\Member;
use App\Support\AppConfig;
use App\Support\Notifications\FollowUpAlert;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Component;

class ChangeDueDateModal extends Component
{
    public ?int $memberId = null;

    public ?int $invoiceId = null;

    public ?int $serviceId = null;

    
    public ?string $newDueDate = null;

    public string $reason = '';

    #[On('open-change-due-date-modal')]
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

        $this->dispatch('open-modal', id: 'change-due-date-modal');
    }

    public function getInvoiceProperty(): ?Invoice
    {
        return $this->invoiceId !== null ? Invoice::find($this->invoiceId) : null;
    }

    

    public function getCanConfirmProperty(): bool
    {
        if (blank($this->newDueDate)) {
            return false;
        }

        try {
            $date = Carbon::parse((string) $this->newDueDate);
        } catch (\Throwable) {
            return false;
        }

        return $date->startOfDay()->isAfter(Carbon::today(AppConfig::timezone())->startOfDay());
    }

    protected function rules(): array
    {
        return [
            'newDueDate' => ['required', 'date', 'after:today'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'newDueDate' => __('app.fields.due_date'),
            'reason' => __('app.check_in.override_message_optional'),
        ];
    }

    public function confirm(): void
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

        try {
            DB::transaction(function () use ($invoice): void {
                $invoice->update([
                    'due_date' => Carbon::parse((string) $this->newDueDate)->toDateString(),
                ]);
            });
        } catch (\Throwable $exception) {
            Log::error('Check-in due-date change failed', [
                'member_id' => $member->id,
                'invoice_id' => $invoice->id,
                'error' => $exception->getMessage(),
            ]);

            $this->notifyDanger(__('app.reception.invoice_not_found'));

            return;
        }

        $serviceId = (int) $this->serviceId;
        $typedReason = filled($this->reason) ? $this->reason : null;

        FollowUpAlert::send(
            action: 'due_date_changed',
            member: $member,
            actor: $actor,
            reason: $typedReason,
            subscription: $invoice->subscription,
            invoice: $invoice->refresh(),
        );

        $this->dispatch('close-modal', id: 'change-due-date-modal');

        $this->resetForm();

        $this->dispatch('check-in.assisted-override',
            serviceId: $serviceId,
            reason: $typedReason ?? __('app.check_in.due_date_updated'),
        );
    }

    public function cancel(): void
    {
        $this->resetForm();

        $this->dispatch('close-modal', id: 'change-due-date-modal');
    }

    private function resetForm(): void
    {
        $this->memberId = null;
        $this->invoiceId = null;
        $this->serviceId = null;
        $this->newDueDate = null;
        $this->reason = '';

        $this->resetErrorBag();
    }

    private function notifyDanger(string $message): void
    {
        $this->dispatch('notify', type: 'danger', message: $message);
    }

    public function render(): View
    {
        return view('livewire.change-due-date-modal');
    }
}
