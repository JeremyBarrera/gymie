# Subscription Quantity Chaining

## The Problem
Quantity on a subscription sale currently just multiplies fee and end_date within a single subscription row, mislabeled "Quantity (months)," and has no session/uses_limit meaning.

## The Decision
Quantity = how many consecutive subscriptions of this plan to create for this member, chained back-to-back by date. Plan-level days and uses_limit never change based on quantity — quantity only controls row count.

## Evergreen Plans
Evergreen plans (isEvergreen() true, no end_date) are lifetime/founder-style passes — quantity is forced to 1, since there's nothing to chain onto.

## Start Date Handling
start_date defaults to the latest non-expired end_date + 1 day for that member+plan (or today if none), but stays staff-editable (e.g. a member on vacation may want a later start) — submitted dates are validated against overlap, not silently trusted.

## Payment Rule (Quantity > 1)
Subscriptions 1..N-1 (all but last) are paid in full at purchase: discount/paid applied normally, `invoices.due_date = today` (purchase date), `paid_amount = total_amount` → status `paid` via existing `Invoice::syncFromTransactions()` / `InvoiceCalculator::summary()`. Subscription N (last) may be unpaid or partially paid: its invoice uses whatever `discount_amount`/`paid_amount` was submitted (including `0` or partial), and `invoices.due_date` defaults to that subscription's own `start_date` when left blank — reusing the same default pattern `SubscriptionRenewalService::renew()` already uses (`due_date ?? start_date`), staff-editable via existing `invoices.date`/`due_date` pickers (`SubscriptionForm.php:170-176`, `SubscriptionForm.php:373-394`). No new `InvoiceCalculator`/`syncFromTransactions`/`partial` status concepts; quantity `=1` unchanged (due today, fully paid).

## Renewal
Renewal is treated as the same operation as buying one more of a plan — it reuses the same shared logic, never mutates the prior subscription.

## Out of Scope
Upgrade/downgrade between different plans is explicitly out of scope for this task — noted as a distinct future feature with its own proration/refund decisions, not solved here.
