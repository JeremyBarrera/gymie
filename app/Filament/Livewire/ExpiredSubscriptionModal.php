<?php

namespace App\Filament\Livewire;

use App\Contracts\TenantContext;
use App\Enums\Status;
use App\Helpers\Helpers;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Subscriptions\SubscriptionRenewalService;
use App\Support\Billing\PaymentMethod;
use App\Support\Notifications\FollowUpAlert;
use App\Filament\Schemas\SubscriptionSaleSchema;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Component;

class ExpiredSubscriptionModal extends Component implements HasSchemas
{
    use InteractsWithSchemas;

    public ?int $memberId = null;

    public ?int $serviceId = null;

    public ?int $previousSubscriptionId = null;

    public array $sales = [];

    #[On('open-expired-subscription-modal')]
    public function open(int $memberId, int $serviceId, ?int $previousSubscriptionId = null): void
    {
        $member = Member::find($memberId);
        $previous = $previousSubscriptionId ? Subscription::find($previousSubscriptionId) : null;

        if (! $member || ($previousSubscriptionId && (! $previous || (int) $previous->member_id !== (int) $member->id))) {
            return;
        }

        $this->resetForm();

        $this->memberId = (int) $member->id;
        $this->serviceId = (int) $serviceId;
        $this->previousSubscriptionId = $previous ? (int) $previous->id : null;

        $defaultPlan = $previous ? Plan::withTrashed()->find($previous->plan_id) : null;
        $available = $this->planOptions;

        $defaultPlanId = $defaultPlan && isset($available[$defaultPlan->id])
            ? (int) $defaultPlan->id
            : (int) array_key_first($available);

        $defaultPlanForDates = Plan::find($defaultPlanId);
        $startDate = now(\App\Support\AppConfig::timezone())->toDateString();
        $endDate = $defaultPlanForDates ? \App\Helpers\Helpers::calculateSubscriptionEndDate($startDate, (int) $defaultPlanForDates->id) : null;

        $this->sales = [[
            'plan_id' => $defaultPlanId,
            'quantity' => 1,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'payment_method' => 'cash',
            'discount_amount' => 0,
            'paid_amount' => 0,
        ]];

        $this->dispatch('open-modal', id: 'expired-subscription-modal');
    }

    

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                \Filament\Forms\Components\Repeater::make('sales')
                    ->label(__('app.titles.membership_plan'))
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->minItems(1)
                    ->defaultItems(1)
                    ->reorderable(false)
                    ->collapsible()
                    ->itemLabel(fn (array $state): ?string => isset($state['plan_id']) && is_numeric($state['plan_id']) ? (Plan::find((int) $state['plan_id'])?->name) : null)
                    ->schema(\App\Filament\Schemas\SubscriptionSaleSchema::fields())
                    ->columns(1),
            ]);
    }

    public function getPlanOptionsProperty(): array
    {
        if ($this->serviceId === null) {
            return [];
        }

        $locationId = app(TenantContext::class)->locationId();

        return Plan::query()
            ->forService($this->serviceId)
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
            'sales' => ['required', 'array', 'min:1'],
            'sales.*.plan_id' => ['required', 'integer', 'in:'.implode(',', array_keys($this->planOptions))],
            'sales.*.quantity' => ['required', 'integer', 'min:1'],
            'sales.*.start_date' => ['required', 'date'],
            'sales.*.end_date' => ['nullable', 'date', 'after_or_equal:sales.*.start_date'],
            'sales.*.payment_method' => ['required', 'string', 'in:'.implode(',', array_keys(PaymentMethod::options()))],
            'sales.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'sales.*.paid_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'sales.*.plan_id' => __('app.fields.plan'),
            'sales.*.payment_method' => __('app.fields.payment_method'),
            'sales.*.start_date' => __('app.fields.start_date'),
            'sales.*.end_date' => __('app.fields.end_date'),
            'sales.*.discount_amount' => __('app.fields.discount'),
            'sales.*.paid_amount' => __('app.fields.paid_amount'),
        ];
    }

    public function submit(): void
    {
        $this->handleSubmit();
    }

    private function handleSubmit(): void
    {
        $actor = Auth::user();

        if (! $actor || ! $actor->can('create', Subscription::class)) {
            $this->notifyDanger(__('app.reception.verify_sale_permission_denied'));

            return;
        }

        $this->validate();

        $member = Member::find($this->memberId);
        $previous = $this->previousSubscriptionId ? Subscription::find($this->previousSubscriptionId) : null;

        if (! $member || ($this->previousSubscriptionId && (! $previous || (int) $previous->member_id !== (int) $member->id))) {
            $this->notifyDanger(__('app.notifications.check_in_failed'));

            return;
        }

        try {
            if ($previous) {
                $results = DB::transaction(function () use ($previous, $member): array {
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

                    $results = [];
                    $currentPrevious = $freshPrevious;

                    foreach ($this->sales as $sale) {
                        $result = app(SubscriptionRenewalService::class)->renew($currentPrevious, [
                            'plan_id' => (int) $sale['plan_id'],
                            'quantity' => max(1, (int) ($sale['quantity'] ?? 1)),
                            'start_date' => (string) $sale['start_date'],
                            'end_date' => filled($sale['end_date'] ?? null) ? (string) $sale['end_date'] : null,
                        'invoice' => [
                            'payment_method' => $sale['payment_method'] ?? 'cash',
                            'discount_amount' => (float) ($sale['discount_amount'] ?? 0),
                            'paid_amount' => (float) ($sale['paid_amount'] ?? 0),
                            'date' => (string) $sale['start_date'],
                        ],
                    ]);
                    $results[] = $result;
                    $currentPrevious = $result['subscription'];
                }

                return $results;
            });
            } else {
                $raw = \App\Services\Subscriptions\MemberSubscriptionService::createForMember($member, $this->sales);
                $results = array_map(fn($pair) => ['subscription' => $pair[0], 'invoice' => $pair[1]], $raw);
            }

            $lastResult = end($results);
            $subscription = $lastResult['subscription'];
            $invoice = $lastResult['invoice'];

            foreach ($results as $result) {
                if ((float) $result['invoice']->due_amount > 0) {
                    FollowUpAlert::send(
                        action: 'new_subscription',
                        member: $member,
                        actor: $actor,
                        reason: __('app.check_in.balance_remaining', ['amount' => Helpers::formatCurrency((float) $result['invoice']->due_amount)]),
                        subscription: $result['subscription'],
                        invoice: $result['invoice'],
                    );
                }
            }
        } catch (\Throwable $exception) {
            Log::error('Expired-path renewal failed', [
                'member_id' => $member->id,
                'previous_subscription_id' => $previous?->id,
                'error' => $exception->getMessage(),
            ]);

            $this->notifyDanger(__('app.notifications.check_in_failed'));

            return;
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
        $this->sales = [];

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
