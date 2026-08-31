<?php

namespace App\Support\Billing;

use App\Helpers\Helpers;
use App\Models\Plan;

class SaleCalculator
{
    public static function recalculate(array $sale): array
    {
        $planId = is_numeric($sale['plan_id'] ?? null) ? (int) $sale['plan_id'] : null;
        $quantity = max(1, (int) ($sale['quantity'] ?? 1));
        $plan = $planId ? Plan::find($planId) : null;
        if ($plan && $plan->isEvergreen()) {
            $quantity = 1;
        }
        $fee = $plan ? (float) $plan->amount * $quantity : 0.0;
        $startDate = (string) ($sale['start_date'] ?? '');
        $endDate = ($plan && $startDate && ! $plan->isEvergreen()) ? Helpers::calculateSubscriptionEndDate($startDate, $planId, $quantity) : null;
        $discount = min(max((float) ($sale['discount_amount'] ?? 0), 0), $fee);
        $paid = (float) ($sale['paid_amount'] ?? 0);
        $summary = InvoiceCalculator::summary($fee, Helpers::getTaxRate() ?: 0, $discount, $paid);
        return array_merge($sale, [
            'quantity' => $quantity,
            'end_date' => $endDate,
            'fee' => $summary['fee'],
            'tax' => $summary['tax'],
            'total' => $summary['total'],
            'due' => $summary['due'],
            'discount_amount' => $summary['discount_amount'],
            'paid_amount' => $summary['paid'],
        ]);
    }
}
