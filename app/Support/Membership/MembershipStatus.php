<?php

namespace App\Support\Membership;

use App\Enums\Status;
use App\Helpers\Helpers;
use App\Models\Invoice;
use App\Models\Member;
use App\Models\Subscription;
use App\Support\AppConfig;
use App\Support\Dates\DeviceDateFormat;
use Carbon\Carbon;

final class MembershipStatus
{
    private const COLOR_GREEN = 'success';

    private const COLOR_YELLOW = 'warning';

    private const COLOR_RED = 'danger';

    private const COLOR_NONE = 'gray';

    private function __construct() {}

    

    public static function forMember(Member $member): array
    {
        $subscription = self::bestSubscription($member);

        if ($subscription === null) {
            return [
                'color' => self::COLOR_NONE,
                'label' => __('app.membership_status.no_membership'),
                'hint' => null,
                'help' => __('app.membership_status.help_no_membership'),
            ];
        }

        $today = Carbon::today(AppConfig::timezone());
        $endDate = $subscription->end_date;
        $planLabel = trim(sprintf(
            '%s - %s',
            (string) ($subscription->plan?->code ?? ''),
            (string) ($subscription->plan?->name ?? ''),
        ));

        if ($endDate === null) {
            return [
                'color' => self::COLOR_GREEN,
                'label' => __('app.membership_status.valid'),
                'hint' => $planLabel,
                'help' => __('app.membership_status.help_valid'),
            ];
        }

        if ($endDate->isBefore($today)) {
            return [
                'color' => self::COLOR_RED,
                'label' => __('app.membership_status.expired_on', ['date' => DeviceDateFormat::format($endDate)]),
                'hint' => $planLabel,
                'help' => __('app.membership_status.help_expired_on', ['date' => DeviceDateFormat::format($endDate)]),
            ];
        }

        $expiringDays = Helpers::getSubscriptionExpiringDays();
        
        
        $daysLeft = (int) $today->diffInDays($endDate);

        if ($daysLeft <= $expiringDays) {
            return [
                'color' => self::COLOR_YELLOW,
                'label' => __('app.membership_status.expires_in_days', ['count' => $daysLeft]),
                'hint' => $planLabel,
                'help' => __('app.membership_status.help_expires_in_days', ['count' => $daysLeft]),
            ];
        }

        return [
            'color' => self::COLOR_GREEN,
            'label' => __('app.membership_status.valid_until', ['date' => DeviceDateFormat::format($endDate)]),
            'hint' => $planLabel,
            'help' => __('app.membership_status.help_valid_until', ['date' => DeviceDateFormat::format($endDate)]),
        ];
    }

    

    public static function isPaymentDueSoon(Member $member): bool
    {
        $expiringDays = Helpers::getSubscriptionExpiringDays();
        $today = Carbon::today(AppConfig::timezone());
        $windowEnd = $today->copy()->addDays($expiringDays);

        return Invoice::query()
            ->whereHas('subscription', fn ($query) => $query
                ->where('member_id', $member->id)
                ->whereIn('status', [Status::Ongoing->value, Status::Expiring->value])
                ->whereDate('start_date', '<=', $today->toDateString())
                ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $today->toDateString())))
            ->whereNotNull('due_date')
            ->where('due_amount', '>', 0)
            ->whereIn('status', [Status::Issued->value, Status::Partial->value])
            ->whereDate('due_date', '>=', $today->toDateString())
            ->whereDate('due_date', '<=', $windowEnd->toDateString())
            ->get()
            ->filter(fn (Invoice $invoice): bool => in_array($invoice->effectiveStatus(), [Status::Issued, Status::Partial], true))
            ->isNotEmpty();
    }

    public static function isExpiringSoon(Member $member): bool
    {
        $expiringDays = Helpers::getSubscriptionExpiringDays();
        $today = Carbon::today(AppConfig::timezone());
        $windowEnd = $today->copy()->addDays($expiringDays);

        return Subscription::query()
            ->where('member_id', $member->id)
            ->whereIn('status', [Status::Ongoing->value, Status::Expiring->value])
            ->whereDate('start_date', '<=', $today->toDateString())
            ->whereNotNull('end_date')
            ->whereDate('end_date', '>=', $today->toDateString())
            ->whereDate('end_date', '<=', $windowEnd->toDateString())
            ->exists();
    }

    public static function expiringSoonEndDate(Member $member): ?Carbon
    {
        $expiringDays = Helpers::getSubscriptionExpiringDays();
        $today = Carbon::today(AppConfig::timezone());
        $windowEnd = $today->copy()->addDays($expiringDays);

        $subscription = Subscription::query()
            ->where('member_id', $member->id)
            ->whereIn('status', [Status::Ongoing->value, Status::Expiring->value])
            ->whereDate('start_date', '<=', $today->toDateString())
            ->whereNotNull('end_date')
            ->whereDate('end_date', '>=', $today->toDateString())
            ->whereDate('end_date', '<=', $windowEnd->toDateString())
            ->orderBy('end_date')
            ->first();

        return $subscription?->end_date;
    }

    public static function remainingUsesForMember(Member $member): ?int
    {
        $subscription = $member->subscriptions()
            ->with('plan')
            ->whereIn('status', [Status::Ongoing->value, Status::Expiring->value])
            ->whereHas('plan', fn ($query) => $query->where('limit_uses', true))
            ->whereDate('start_date', '<=', Carbon::today(AppConfig::timezone())->toDateString())
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', Carbon::today(AppConfig::timezone())->toDateString()))
            ->orderByDesc('end_date')
            ->first();

        if ($subscription === null) {
            return null;
        }

        return app(\App\Services\Membership\PlanCheckInService::class)->remainingUses($subscription);
    }

    private static function bestSubscription(Member $member): ?Subscription
    {
        return $member->subscriptions()
            ->with('plan')
            ->whereIn('status', [Status::Ongoing->value, Status::Expiring->value, Status::Expired->value])
            ->orderByRaw('end_date IS NULL DESC')
            ->orderByDesc('end_date')
            ->first();
    }
}
