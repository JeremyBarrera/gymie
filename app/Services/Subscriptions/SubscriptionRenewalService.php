<?php

namespace App\Services\Subscriptions;

use App\Helpers\Helpers;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Subscriptions\SubscriptionChainService;
use App\Support\AppConfig;
use App\Support\Data;
use Carbon\Carbon;

class SubscriptionRenewalService
{
    

    public function renew(Subscription $record, array $data): array
    {
        $result = Subscription::query()->getConnection()->transaction(function () use ($record, $data): array {
            $timezone = AppConfig::timezone();
            $today = Carbon::today($timezone);
            $plan = Plan::findOrFail(Data::int($data['plan_id']));
            $member = $record->member;
            $startDate = Carbon::parse(Data::string($data['start_date'] ?? SubscriptionChainService::nextStartDate($member, $plan)))->toDateString();
            $endDate = $plan->isEvergreen() ? null : Helpers::calculateSubscriptionEndDate($startDate, Data::int($plan->id), 1);
            $conflict = SubscriptionChainService::overlaps($member, $plan, $startDate, $endDate);
            if ($conflict) {
                $endDisplay = $conflict->end_date ? $conflict->end_date->toDateString() : __('app.fields.unlimited');
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'start_date' => [__('app.validation.subscription_overlap', ['end' => $endDisplay])],
                ]);
            }

            $status = Carbon::parse($startDate)->gt($today)
                ? 'upcoming'
                : 'ongoing';

            $newSubscription = Subscription::create([
                'renewed_from_subscription_id' => $record->id,
                'member_id' => $record->member_id,
                'plan_id' => $plan->id,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'status' => $status,
            ]);

            if ($record->end_date && $record->end_date->lt($today)) {
                $record->update([
                    'status' => 'renewed',
                ]);
            }

            $invoiceData = $data['invoice'] ?? [];

            $fee = round(Data::float($plan->amount), 2);

            $discountPct = max(Data::int($invoiceData['discount'] ?? 0), 0);
            $discountAmount = Data::float($invoiceData['discount_amount'] ?? 0);
            $discountAmount = min(max($discountAmount, 0), $fee);
            if ($discountPct > 0 && $discountAmount <= 0) {
                $discountAmount = (float) Helpers::getDiscountAmount($discountPct, $fee);
            }

            $paymentMethod = Data::nullableString($invoiceData['payment_method'] ?? null);
            $paidAmount = max(Data::float($invoiceData['paid_amount'] ?? 0), 0);

            $invoiceDate = Carbon::parse(Data::string($invoiceData['date'] ?? $startDate))->toDateString();
            $invoiceDueDate = Carbon::parse(Data::string($invoiceData['due_date'] ?? $invoiceDate))->toDateString();

            $invoice = Invoice::create([
                'number' => $invoiceData['number'] ?? null,
                'subscription_id' => $newSubscription->id,
                'date' => $invoiceDate,
                'due_date' => $invoiceDueDate,
                'payment_method' => $paymentMethod,
                'discount' => $discountPct ?: null,
                'discount_amount' => $discountAmount ?: null,
                'discount_note' => $invoiceData['discount_note'] ?? null,
                'paid_amount' => $paidAmount,
                'subscription_fee' => $fee,
                'status' => 'issued',
            ]);

            return [
                'subscription' => $newSubscription->refresh(),
                'invoice' => $invoice->refresh(),
            ];
        });

        return $result;
    }
}
