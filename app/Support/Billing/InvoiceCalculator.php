<?php

namespace App\Support\Billing;

final class InvoiceCalculator
{
    

    public static function summary(
        float $fee,
        float $taxRatePercent,
        float $discountAmount,
        float $paidAmount,
    ): array {
        $fee = max(round($fee), 0.0);
        $taxRatePercent = max($taxRatePercent, 0.0);

        $tax = round(($fee * $taxRatePercent) / 100);

        $discountAmount = min(max($discountAmount, 0.0), $fee);
        $discountAmount = round($discountAmount);

        $total = round(max($fee + $tax - $discountAmount, 0.0));

        $paidAmount = min(max($paidAmount, 0.0), $total);
        $paidAmount = round($paidAmount);

        $due = round(max($total - $paidAmount, 0.0));

        return [
            'fee' => (float) $fee,
            'tax' => (float) $tax,
            'discount_amount' => (float) $discountAmount,
            'total' => (float) $total,
            'paid' => (float) $paidAmount,
            'due' => (float) $due,
        ];
    }
}
