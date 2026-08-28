<?php

namespace App\Services\Subscriptions;

use App\Enums\Status;
use App\Helpers\Helpers;
use App\Models\Invoice;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\AppConfig;
use App\Support\Data;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MemberSubscriptionService
{
    public static function createForMember(Member $member, array $sales): array
    {
        return DB::transaction(function () use ($member, $sales): array {
            $results = [];
            foreach ($sales as $sale) {
                $results[] = self::createSingle($member, $sale);
            }
            if ($member->status === Status::Pending) {
                $member->update(['status' => Status::Active]);
            }
            return $results;
        });
    }

    public static function createSingle(Member $member, array $validated): array
    {
        $invoiceData = is_array($validated['invoices'] ?? null) ? (reset($validated['invoices']) ?: []) : [];
        $plan = Plan::findOrFail(Data::int($validated['plan_id'] ?? $invoiceData['plan_id'] ?? null) ?: Data::int($validated['plan_id'] ?? null));
        $quantity = max(1, Data::int($validated['quantity'] ?? $invoiceData['quantity'] ?? 1));
        $startDate = Carbon::parse(Data::string($validated['start_date'] ?? $invoiceData['start_date'] ?? now()->toDateString()))->toDateString();
        $endDate = Data::string($validated['end_date'] ?? $invoiceData['end_date'] ?? null) ?: ($plan->isEvergreen() ? null : Helpers::calculateSubscriptionEndDate($startDate, Data::int($plan->id), $quantity));
        $status = Carbon::parse($startDate)->gt(Carbon::today(AppConfig::timezone())) ? 'upcoming' : 'ongoing';
        $subscription = Subscription::create([
            'member_id' => $member->id,
            'plan_id' => $plan->id,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => $status,
            'location_id' => $plan->location_id,
        ]);
        $fee = round(Data::float($plan->amount) * $quantity);
        $discountPct = max(Data::int($invoiceData['discount'] ?? $validated['discount'] ?? 0), 0);
        $discountAmount = Data::float($invoiceData['discount_amount'] ?? $validated['discount_amount'] ?? 0);
        $discountAmount = min(max($discountAmount, 0), $fee);
        if ($discountPct > 0 && $discountAmount <= 0) {
            $discountAmount = Helpers::getDiscountAmount($discountPct, $fee);
        }
        $paidAmount = max(Data::float($invoiceData['paid_amount'] ?? $validated['paid_amount'] ?? 0), 0);
        $paymentMethod = Data::nullableString($invoiceData['payment_method'] ?? $validated['payment_method'] ?? null) ?: 'cash';
        $invoiceDate = isset($invoiceData['date']) ? Carbon::parse(Data::string($invoiceData['date']))->toDateString() : Carbon::today(AppConfig::timezone())->toDateString();
        $invoiceDueDate = isset($invoiceData['due_date']) ? Carbon::parse(Data::string($invoiceData['due_date']))->toDateString() : $invoiceDate;
        $invoiceNumber = $invoiceData['number'] ?? $invoiceData['invoice_number'] ?? $validated['invoice_number'] ?? Helpers::generateLastNumber('invoice', Invoice::class, $invoiceDate);
        $invoice = Invoice::create([
            'number' => $invoiceNumber,
            'subscription_id' => $subscription->id,
            'date' => $invoiceDate,
            'due_date' => $invoiceDueDate,
            'payment_method' => $paymentMethod,
            'discount' => $discountPct ?: null,
            'discount_amount' => $discountAmount ?: null,
            'discount_note' => $invoiceData['discount_note'] ?? $validated['discount_note'] ?? null,
            'paid_amount' => $paidAmount,
            'subscription_fee' => $fee,
            'status' => 'issued',
            'location_id' => $plan->location_id,
        ]);
        return [$subscription, $invoice];
    }
}
