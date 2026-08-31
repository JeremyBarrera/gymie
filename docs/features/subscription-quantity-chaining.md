# Subscription Quantity Chaining

## The Problem
Quantity on a subscription sale currently just multiplies fee and end_date within a single subscription row, mislabeled "Quantity (months)," and has no session/uses_limit meaning.

## The Decision
Quantity = how many consecutive subscriptions of this plan to create for this member, chained back-to-back by date. Plan-level days and uses_limit never change based on quantity — quantity only controls row count.

## Evergreen Plans
Evergreen plans (isEvergreen() true, no end_date) are lifetime/founder-style passes — quantity is forced to 1, since there's nothing to chain onto.

## Start Date Handling
start_date defaults to the latest non-expired end_date + 1 day for that member+plan (or today if none), but stays staff-editable (e.g. a member on vacation may want a later start) — submitted dates are validated against overlap, not silently trusted.

## Renewal
Renewal is treated as the same operation as buying one more of a plan — it reuses the same shared logic, never mutates the prior subscription.

## Out of Scope
Upgrade/downgrade between different plans is explicitly out of scope for this task — noted as a distinct future feature with its own proration/refund decisions, not solved here.
