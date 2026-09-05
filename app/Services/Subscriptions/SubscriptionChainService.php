<?php

namespace App\Services\Subscriptions;

use App\Helpers\Helpers;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\AppConfig;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SubscriptionChainService
{
    public static function nextStartDate(Member $member, Plan $plan): string
    {
        $compute = function () use ($member, $plan): string {
            $latest = Subscription::where('member_id', $member->id)
                ->where('plan_id', $plan->id)
                ->whereIn('status', ['ongoing', 'upcoming', 'expiring'])
                ->whereNotNull('end_date')
                ->lockForUpdate()
                ->max('end_date');
            if ($latest) {
                return Carbon::parse($latest)->addDay()->toDateString();
            }
            return Carbon::today(AppConfig::timezone())->toDateString();
        };
        if (DB::transactionLevel() > 0) {
            return $compute();
        }
        return DB::transaction($compute);
    }

    public static function overlaps(Member $member, Plan $plan, string $startDate, ?string $endDate): ?Subscription
    {
        $start = Carbon::parse($startDate)->toDateString();
        $end = $endDate ? Carbon::parse($endDate)->toDateString() : null;
        $query = Subscription::where('member_id', $member->id)
            ->where('plan_id', $plan->id)
            ->whereIn('status', ['ongoing', 'upcoming', 'expiring'])
            ->lockForUpdate();
        if ($end) {
            $query->where(function ($q) use ($start, $end) {
                $q->where(function ($qq) use ($start, $end) {
                    $qq->where('start_date', '<=', $end)->where(function ($qqq) use ($start) {
                        $qqq->whereNull('end_date')->orWhere('end_date', '>=', $start);
                    });
                });
            });
        } else {
            $query->where(function ($q) use ($start) {
                $q->where('end_date', '>=', $start)->orWhereNull('end_date');
            });
        }
        return $query->first();
    }

    public static function chainOverlaps(Member $member, Plan $plan, string $baseStart, int $quantity): ?Subscription
    {
        $quantity = $plan->isEvergreen() ? 1 : max(1, $quantity);
        $prevEnd = null;
        for ($i = 0; $i < $quantity; $i++) {
            $start = $i === 0 ? $baseStart : Carbon::parse($prevEnd)->addDay()->toDateString();
            $end = $plan->isEvergreen() ? null : Helpers::calculateSubscriptionEndDate($start, $plan->id, 1);
            $conflict = self::overlaps($member, $plan, $start, $end);
            if ($conflict) {
                return $conflict;
            }
            $prevEnd = $end;
            if ($plan->isEvergreen()) {
                break;
            }
        }
        return null;
    }
}
