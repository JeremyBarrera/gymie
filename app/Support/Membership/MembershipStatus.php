<?php

namespace App\Support\Membership;

use App\Enums\Status;
use App\Helpers\Helpers;
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
