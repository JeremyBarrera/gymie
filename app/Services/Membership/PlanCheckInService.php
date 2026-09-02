<?php

namespace App\Services\Membership;

use App\Contracts\TenantContext;
use App\Enums\Status;
use App\Exceptions\PlanCheckIn\DuplicateCheckInRequiresConfirmationException;
use App\Exceptions\PlanCheckIn\MemberInactiveException;
use App\Exceptions\PlanCheckIn\OverdueInvoiceException;
use App\Exceptions\PlanCheckIn\SubscriptionNotEligibleException;
use App\Exceptions\PlanCheckIn\UsesExceededException;
use App\Helpers\Helpers;
use App\Models\Invoice;
use App\Models\Member;
use App\Models\PlanCheckIn;
use App\Models\Service;
use App\Models\Subscription;
use App\Models\User;
use App\Support\AppConfig;
use App\Support\Dates\DeviceDateFormat;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PlanCheckInService
{
    

    public function eligibleSubscriptions(Member $member): Collection
    {
        
        
        if ($member->checkInBlocker() !== null) {
            return new Collection;
        }

        $today = Carbon::today(AppConfig::timezone())->toDateString();

        
        
        $locationId = app(TenantContext::class)->locationId();

        return $member->subscriptions()
            ->with(['plan.services', 'plan.location'])
            ->whereIn('status', [Status::Ongoing->value, Status::Expiring->value])
            ->whereDate('start_date', '<=', $today)
            ->where(fn ($query) => $query
                ->whereNull('end_date')
                ->orWhereDate('end_date', '>=', $today))
            ->whereHas('plan', fn ($query) => $query
                ->where('status', Status::Active->value)
                ->has('services'))
            ->orderBy('end_date')
            ->get()
            ->filter(fn (Subscription $subscription): bool => (bool) $subscription->plan?->availableAt($locationId))
            ->values();
    }

    

    public function usedCount(Subscription $subscription): int
    {
        $start = $subscription->start_date?->copy()->startOfDay();
        $end = $subscription->end_date?->copy()->endOfDay();

        if ($start === null) {
            return 0;
        }

        
        return PlanCheckIn::query()
            ->where('subscription_id', $subscription->id)
            ->where('override', false)
            ->when($end === null, fn ($query) => $query->where('checked_in_at', '>=', $start))
            ->when($end !== null, fn ($query) => $query->whereBetween('checked_in_at', [$start, $end]))
            ->count();
    }

    

    public function remainingUses(Subscription $subscription): ?int
    {
        $subscription->loadMissing('plan');
        $plan = $subscription->plan;

        if ($plan === null || ! $plan->limit_uses) {
            return null;
        }

        $limit = (int) ($plan->uses_limit ?? 0);

        return max($limit - $this->usedCount($subscription), 0);
    }

    

    public function hasCheckedInToday(Subscription $subscription): bool
    {
        $timezone = AppConfig::timezone();
        $start = Carbon::today($timezone)->startOfDay();
        $end = Carbon::today($timezone)->endOfDay();

        return PlanCheckIn::query()
            ->where('subscription_id', $subscription->id)
            ->whereBetween('checked_in_at', [$start, $end])
            ->exists();
    }

    

    public function hasOverdueInvoice(Member $member): bool
    {
        return $this->overdueInvoice($member) !== null;
    }

    

    public function overdueInvoice(Member $member): ?Invoice
    {
        return Invoice::query()
            ->whereHas('subscription', fn ($query) => $query->where('member_id', $member->id))
            ->get()
            ->filter(fn (Invoice $invoice): bool => $invoice->effectiveStatus() === Status::Overdue)
            ->sortBy('due_date')
            ->first();
    }

    

    public function serviceStatesForMember(Member $member, ?int $locationId = null): array
    {
        $locationId ??= app(TenantContext::class)->locationId();

        $services = Service::query()
            ->where(fn ($query) => $query->where('location_id', $locationId)->orWhereNull('location_id'))
            ->orderBy('name')
            ->get();

        $eligible = $this->eligibleSubscriptions($member);
        $memberOverdueInvoice = $this->overdueInvoice($member);

        $allSubscriptions = $member->subscriptions()
            ->with('plan.services')
            ->get();

        $states = [];

        foreach ($services as $service) {
            $best = $eligible
                ->filter(fn (Subscription $subscription): bool => (bool) $subscription->plan?->services?->contains('id', (int) $service->id))
                ->sortByDesc('end_date')
                ->first();

            if ($memberOverdueInvoice) {
                $states[] = [
                    'id' => (int) $service->id,
                    'name' => (string) $service->name,
                    'state' => 'overdue',
                    'subscription_id' => $best?->id,
                    'warning' => __('app.reception.service_overdue', [
                        'date' => DeviceDateFormat::format($memberOverdueInvoice->due_date),
                    ]),
                ];

                continue;
            }

            if ($best === null) {
                $hasAnySubscription = $allSubscriptions
                    ->contains(fn (Subscription $s): bool => (bool) $s->plan?->services?->contains('id', (int) $service->id));

                $state = $hasAnySubscription ? 'expired' : 'no_access';

                $states[] = [
                    'id' => (int) $service->id,
                    'name' => (string) $service->name,
                    'state' => $state,
                    'subscription_id' => null,
                    'warning' => $hasAnySubscription
                        ? __('app.reception.service_expired')
                        : __('app.reception.service_no_access'),
                ];

                continue;
            }

            $invoice = $best->invoices()->latest('due_date')->first();
            $status = $invoice?->effectiveStatus();

            if ($status === Status::Overdue) {
                $states[] = [
                    'id' => (int) $service->id,
                    'name' => (string) $service->name,
                    'state' => 'overdue',
                    'subscription_id' => $best->id,
                    'warning' => __('app.reception.service_overdue', [
                        'date' => DeviceDateFormat::format($invoice->due_date),
                    ]),
                ];

                continue;
            }

            if ($this->remainingUses($best) === 0) {
                $states[] = [
                    'id' => (int) $service->id,
                    'name' => (string) $service->name,
                    'state' => 'uses_exhausted',
                    'subscription_id' => $best->id,
                    'warning' => __('app.reception.service_uses_exhausted', [
                        'plan' => (string) $best->plan?->name,
                    ]),
                ];

                continue;
            }

            if ($this->hasCheckedInToday($best) && $this->remainingUses($best) !== null) {
                $states[] = [
                    'id' => (int) $service->id,
                    'name' => (string) $service->name,
                    'state' => 'same_day_duplicate',
                    'subscription_id' => $best->id,
                    'warning' => __('app.reception.service_same_day_duplicate', [
                        'plan' => (string) $best->plan?->name,
                    ]),
                ];

                continue;
            }

            if (in_array($status, [Status::Issued, Status::Partial], true) && (float) ($invoice->due_amount ?? 0) > 0) {
                $states[] = [
                    'id' => (int) $service->id,
                    'name' => (string) $service->name,
                    'state' => 'unpaid',
                    'subscription_id' => $best->id,
                    'warning' => __('app.reception.service_unpaid', [
                        'date' => DeviceDateFormat::format($invoice->due_date),
                        'amount' => Helpers::formatCurrency((float) $invoice->due_amount),
                    ]),
                ];

                continue;
            }

            $states[] = [
                'id' => (int) $service->id,
                'name' => (string) $service->name,
                'state' => 'access',
                'subscription_id' => $best->id,
                'warning' => null,
            ];
        }

        return $states;
    }

    

    public function checkIn(
        Member $member,
        Subscription $subscription,
        ?User $staff = null,
        bool $confirmDuplicate = false,
        ?int $locationId = null,
    ): PlanCheckIn {
        if ($member->checkInBlocker() === 'banned') {
            throw new MemberInactiveException(__('app.reception.check_in_member_banned'));
        }

        if ($this->hasOverdueInvoice($member)) {
            throw new OverdueInvoiceException(__('app.notifications.check_in_overdue_invoice'));
        }

        if ((int) $subscription->member_id !== (int) $member->id) {
            throw new SubscriptionNotEligibleException(__('app.notifications.check_in_not_eligible'));
        }

        $eligible = $this->eligibleSubscriptions($member)
            ->contains(fn (Subscription $item): bool => (int) $item->id === (int) $subscription->id);

        if (! $eligible) {
            throw new SubscriptionNotEligibleException(__('app.notifications.check_in_not_eligible'));
        }

        $subscription->loadMissing('plan');
        $plan = $subscription->plan;

        if ($plan === null) {
            throw new SubscriptionNotEligibleException(__('app.notifications.check_in_not_eligible'));
        }

        $remaining = $this->remainingUses($subscription);

        if ($remaining !== null && $remaining <= 0) {
            throw new UsesExceededException(__('app.notifications.check_in_uses_exceeded'));
        }

        if (! $confirmDuplicate && $this->hasCheckedInToday($subscription)) {
            throw new DuplicateCheckInRequiresConfirmationException(
                __('app.notifications.check_in_duplicate_today')
            );
        }

        return DB::transaction(function () use ($member, $subscription, $plan, $staff, $locationId): PlanCheckIn {
            return PlanCheckIn::query()->create([
                'member_id' => $member->id,
                'subscription_id' => $subscription->id,
                'plan_id' => $plan->id,
                'service_id' => $plan->primaryService()?->id,
                'location_id' => $locationId ?? app(TenantContext::class)->locationId(),
                'checked_in_by' => $staff?->id,
                'checked_in_at' => now(AppConfig::timezone()),
            ]);
        });
    }

    

    public function checkInOverride(
        Member $member,
        ?Subscription $subscription,
        ?User $staff = null,
        ?string $reason = null,
        bool $skipOverdueCheck = false,
        ?int $serviceId = null,
        ?int $locationId = null,
    ): PlanCheckIn {
        $blocker = $member->checkInBlocker();

        if ($blocker === 'banned') {
            throw new MemberInactiveException(__('app.reception.check_in_member_banned'));
        }

        if (! $skipOverdueCheck && $this->hasOverdueInvoice($member)) {
            throw new OverdueInvoiceException(__('app.notifications.check_in_overdue_invoice'));
        }

        $plan = null;

        if ($subscription) {
            $subscription->loadMissing('plan');
            $plan = $subscription->plan;

            if ($plan === null) {
                throw new SubscriptionNotEligibleException(__('app.notifications.check_in_not_eligible'));
            }
        }

        return DB::transaction(function () use ($member, $subscription, $plan, $staff, $reason, $serviceId, $locationId): PlanCheckIn {
            return PlanCheckIn::query()->create([
                'member_id' => $member->id,
                'subscription_id' => $subscription?->id,
                'plan_id' => $plan?->id,
                'service_id' => $plan?->primaryService()?->id ?? $serviceId,
                'override' => true,
                'override_by_user_id' => $staff?->id,
                'override_reason' => $reason,
                'location_id' => $locationId ?? app(TenantContext::class)->locationId(),
                'checked_in_by' => $staff?->id,
                'checked_in_at' => now(AppConfig::timezone()),
            ]);
        });
    }

    

    public function subscriptionOptionLabel(Subscription $subscription): string
    {
        $subscription->loadMissing(['plan.services']);
        $plan = $subscription->plan;
        $serviceName = $plan?->primaryService()?->name;
        $remaining = $this->remainingUses($subscription);

        $usesLabel = $remaining === null
            ? __('app.fields.unlimited')
            : __('app.fields.uses_remaining', ['count' => $remaining]);

        $planLabel = trim(sprintf(
            '%s - %s',
            (string) ($plan?->code ?? ''),
            (string) ($plan?->name ?? ''),
        ));

        if (filled($serviceName)) {
            $planLabel .= sprintf(' (%s)', $serviceName);
        }

        return $planLabel.' · '.$usesLabel;
    }
}
