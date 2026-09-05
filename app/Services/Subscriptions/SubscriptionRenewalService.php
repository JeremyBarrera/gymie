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
            $quantity = $plan->isEvergreen() ? 1 : max(1, (int) ($data['quantity'] ?? 1));
            $invoiceData = $data['invoice'] ?? [];
            $fee = round(Data::float($plan->amount), 2);
            $startDate = Carbon::parse(Data::string($data['start_date'] ?? SubscriptionChainService::nextStartDate($member, $plan)))->toDateString();

            $subscriptions = [];
            $invoices = [];
            $previousId = $record->id;
            $cycleStart = $startDate;
            $firstInvoiceDate = $startDate;
            $prevEnd = null;

            for ($cycle = 0; $cycle < $quantity; $cycle++) {
                if ($cycle > 0) {
                    $cycleStart = Carbon::parse($prevEnd)->addDay()->toDateString();
                }

                $endDate = $plan->isEvergreen() ? null : Helpers::calculateSubscriptionEndDate($cycleStart, Data::int($plan->id), 1);
                $conflict = SubscriptionChainService::overlaps($member, $plan, $cycleStart, $endDate);
                if ($conflict) {
                    $endDisplay = $conflict->end_date ? $conflict->end_date->toDateString() : __('app.fields.unlimited');
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'start_date' => [__('app.validation.subscription_overlap', ['end' => $endDisplay])],
                    ]);
                }

                $status = Carbon::parse($cycleStart)->gt($today)
                    ? 'upcoming'
                    : 'ongoing';

                $newSubscription = Subscription::create([
                    'renewed_from_subscription_id' => $previousId,
                    'member_id' => $record->member_id,
                    'plan_id' => $plan->id,
                    'start_date' => $cycleStart,
                    'end_date' => $endDate,
                    'status' => $status,
                ]);

                $firstCycle = $cycle === 0;
                $discountPct = $firstCycle ? max(Data::int($invoiceData['discount'] ?? 0), 0) : 0;
                $discountAmount = $firstCycle ? Data::float($invoiceData['discount_amount'] ?? 0) : 0;
                $discountAmount = min(max($discountAmount, 0), $fee);
                if ($discountPct > 0 && $discountAmount <= 0) {
                    $discountAmount = (float) Helpers::getDiscountAmount($discountPct, $fee);
                }

                $paymentMethod = $firstCycle ? Data::nullableString($invoiceData['payment_method'] ?? null) : null;
                $paidAmount = $firstCycle ? max(Data::float($invoiceData['paid_amount'] ?? 0), 0) : 0;

                if ($firstCycle) {
                    $invoiceDate = Carbon::parse(Data::string($invoiceData['date'] ?? $cycleStart))->toDateString();
                    $firstInvoiceDate = $invoiceDate;
                } else {
                    $invoiceDate = $firstInvoiceDate;
                }
                $invoiceDueDate = $firstCycle
                    ? Carbon::parse(Data::string($invoiceData['due_date'] ?? $invoiceDate))->toDateString()
                    : $cycleStart;

                $invoiceNumber = Helpers::generateLastNumber('invoice', Invoice::class, $invoiceDate);
                if ($firstCycle && Data::string($invoiceData['number'] ?? null)) {
                    $invoiceNumber = Data::string($invoiceData['number']);
                }

                $invoice = Invoice::create([
                    'number' => $invoiceNumber,
                    'subscription_id' => $newSubscription->id,
                    'date' => $invoiceDate,
                    'due_date' => $invoiceDueDate,
                    'payment_method' => $paymentMethod,
                    'discount' => $discountPct ?: null,
                    'discount_amount' => $discountAmount ?: null,
                    'discount_note' => $firstCycle ? ($invoiceData['discount_note'] ?? null) : null,
                    'paid_amount' => $paidAmount,
                    'subscription_fee' => $fee,
                    'status' => 'issued',
                ]);

                $subscriptions[] = $newSubscription->refresh();
                $invoices[] = $invoice->refresh();
                $previousId = $newSubscription->id;
                $prevEnd = $endDate;

                if ($plan->isEvergreen()) {
                    break;
                }
            }

            if ($record->end_date && $record->end_date->lt($today)) {
                $record->update([
                    'status' => 'renewed',
                ]);
            }

            return [
                'subscription' => end($subscriptions),
                'invoice' => end($invoices),
                'subscriptions' => $subscriptions,
                'invoices' => $invoices,
            ];
        });

        return $result;
    }
}
