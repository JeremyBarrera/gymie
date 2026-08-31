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
                $plan = Plan::findOrFail(Data::int($sale['plan_id'] ?? null));
                $quantity = max(1, Data::int($sale['quantity'] ?? 1));
                if ($plan->isEvergreen()) {
                    $quantity = 1;
                }
                $baseStart = Data::string($sale['start_date'] ?? null) ?: SubscriptionChainService::nextStartDate($member, $plan);
                $today = Carbon::today(AppConfig::timezone());
                $conflict = SubscriptionChainService::chainOverlaps($member, $plan, $baseStart, $quantity);
                if ($conflict) {
                    $endDisplay = $conflict->end_date ? $conflict->end_date->toDateString() : __('app.fields.unlimited');
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'sales' => [__('app.validation.subscription_overlap', ['end' => $endDisplay])],
                    ]);
                }
                $prevEnd = null;
                for ($i = 0; $i < $quantity; $i++) {
                    $start = $i === 0 ? $baseStart : Carbon::parse($prevEnd)->addDay()->toDateString();
                    $end = $plan->isEvergreen() ? null : Helpers::calculateSubscriptionEndDate($start, $plan->id, 1);
                    $status = Carbon::parse($start)->gt($today) ? 'upcoming' : 'ongoing';
                    $subscription = Subscription::create([
                        'member_id' => $member->id,
                        'plan_id' => $plan->id,
                        'start_date' => $start,
                        'end_date' => $end,
                        'status' => $status,
                        'location_id' => $plan->location_id,
                    ]);
                    $fee = round(Data::float($plan->amount));
                    $discountPct = 0;
                    $discountAmount = 0;
                    $paidAmount = 0;
                    $paymentMethod = Data::nullableString($sale['payment_method'] ?? null) ?: 'cash';
                    if ($i === 0) {
                        $discountAmount = min(max(Data::float($sale['discount_amount'] ?? 0), 0), $fee);
                        $paidAmount = max(Data::float($sale['paid_amount'] ?? 0), 0);
                    }
                    $invoiceDate = $today->toDateString();
                    $invoice = Invoice::create([
                        'number' => Helpers::generateLastNumber('invoice', Invoice::class, $invoiceDate),
                        'subscription_id' => $subscription->id,
                        'date' => $invoiceDate,
                        'due_date' => $invoiceDate,
                        'payment_method' => $paymentMethod,
                        'discount' => $discountPct ?: null,
                        'discount_amount' => $discountAmount ?: null,
                        'discount_note' => $sale['discount_note'] ?? null,
                        'paid_amount' => $paidAmount,
                        'subscription_fee' => $fee,
                        'status' => 'issued',
                        'location_id' => $plan->location_id,
                    ]);
                    $results[] = [$subscription, $invoice];
                    $prevEnd = $end;
                    if ($plan->isEvergreen()) {
                        break;
                    }
                }
            }
            if ($member->status === Status::Pending) {
                $member->update(['status' => Status::Active]);
            }
            return $results;
        });
    }

    public static function createSingle(Member $member, array $validated): array
    {
        return DB::transaction(function () use ($member, $validated): array {
            $invoiceData = is_array($validated['invoices'] ?? null) ? (reset($validated['invoices']) ?: []) : [];
            $plan = Plan::findOrFail(Data::int($validated['plan_id'] ?? $invoiceData['plan_id'] ?? null) ?: Data::int($validated['plan_id'] ?? null));
            $quantity = max(1, Data::int($validated['quantity'] ?? $invoiceData['quantity'] ?? 1));
            if ($plan->isEvergreen()) {
                $quantity = 1;
            }
            $baseStart = Data::string($validated['start_date'] ?? $invoiceData['start_date'] ?? null) ?: SubscriptionChainService::nextStartDate($member, $plan);
            $conflict = SubscriptionChainService::chainOverlaps($member, $plan, $baseStart, $quantity);
            if ($conflict) {
                $endDisplay = $conflict->end_date ? $conflict->end_date->toDateString() : __('app.fields.unlimited');
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'start_date' => [__('app.validation.subscription_overlap', ['end' => $endDisplay])],
                ]);
            }
            $today = Carbon::today(AppConfig::timezone());
            $prevEnd = null;
            $firstResult = null;
            for ($i = 0; $i < $quantity; $i++) {
                $start = $i === 0 ? $baseStart : Carbon::parse($prevEnd)->addDay()->toDateString();
                $end = $plan->isEvergreen() ? null : Helpers::calculateSubscriptionEndDate($start, $plan->id, 1);
                $status = Carbon::parse($start)->gt($today) ? 'upcoming' : 'ongoing';
                $subscription = Subscription::create([
                    'member_id' => $member->id,
                    'plan_id' => $plan->id,
                    'start_date' => $start,
                    'end_date' => $end,
                    'status' => $status,
                    'location_id' => $plan->location_id,
                ]);
                $fee = round(Data::float($plan->amount));
                $discountPct = 0;
                $discountAmount = 0;
                $paidAmount = 0;
                $paymentMethod = Data::nullableString($invoiceData['payment_method'] ?? $validated['payment_method'] ?? null) ?: 'cash';
                if ($i === 0) {
                    $discountPct = max(Data::int($invoiceData['discount'] ?? $validated['discount'] ?? 0), 0);
                    $discountAmount = Data::float($invoiceData['discount_amount'] ?? $validated['discount_amount'] ?? 0);
                    $discountAmount = min(max($discountAmount, 0), $fee);
                    if ($discountPct > 0 && $discountAmount <= 0) {
                        $discountAmount = Helpers::getDiscountAmount($discountPct, $fee);
                    }
                    $paidAmount = max(Data::float($invoiceData['paid_amount'] ?? $validated['paid_amount'] ?? 0), 0);
                }
                $invoiceDate = isset($invoiceData['date']) ? Carbon::parse(Data::string($invoiceData['date']))->toDateString() : $today->toDateString();
                $invoiceDueDate = isset($invoiceData['due_date']) ? Carbon::parse(Data::string($invoiceData['due_date']))->toDateString() : $invoiceDate;
                $invoiceNumber = Helpers::generateLastNumber('invoice', Invoice::class, $invoiceDate);
                if ($i === 0) {
                    $invoiceNumber = $invoiceData['number'] ?? $invoiceData['invoice_number'] ?? $validated['invoice_number'] ?? $invoiceNumber;
                }
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
                if ($i === 0) {
                    $firstResult = [$subscription, $invoice];
                }
                $prevEnd = $end;
                if ($plan->isEvergreen()) {
                    break;
                }
            }
            return $firstResult;
        });
    }
}
