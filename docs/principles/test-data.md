# Test Data Principles — Real Validation vs Contrived Seeds

Real validation uses real flows, not hand-crafted seed states.

Do not validate check-in or membership states by hand-inserting `subscriptions`, `invoices`, or `plan_check_ins` rows that force a state. Example of what NOT to do: creating a fake `TestOverrideMembersSeeder` member with an artificially unpaid invoice plus exhausted uses, then claiming that proves a reorder or severity change is correct.

Correct approach: create a normal member through the real application entry point, attach a real subscription for a real plan, and let invoices and check-ins generate with real future due dates and real `limit_uses` counts. Let the production logic surface the state naturally:

- `PlanCheckInService::serviceStatesForMember` determines `unpaid`, `overdue`, `uses_exhausted`, `same_day_duplicate`
- `Invoice::effectiveStatus` determines `Overdue` vs `Issued`/`Partial`
- `PlanCheckInService::remainingUses` determines `uses_exhausted`
- `PlanCheckInService::hasCheckedInToday` determines `same_day_duplicate`

Because the state is derived from the same code that runs in production, the test actually validates the reorder and severity ranking. Hand-crafted rows that set `subscriptions.end_date` or `invoices.due_amount` or `plan_check_ins.checked_in_at` manually bypass those business rules, hide regressions, and give false confidence.

Guidelines:

- For manual UI spot-checks, `TestOverrideMembersSeeder` may be used to quickly render each card state, but never as proof that business logic is correct.
- For correctness proofs, write a script or factory that calls the same helpers the app uses: `Helpers::combinePhoneField`, `Member::create`, `Subscription::create` with `status = Ongoing/Expiring`, `Invoice` with `effectiveStatus` derived, `PlanCheckIn` via `PlanCheckInService::checkIn`.
- Keep `plan.limit_uses` and `plan.uses_limit` realistic. If a plan is limited, create the exact number of `plan_check_ins` via the service to exhaust it, then assert the resulting state.
- Keep invoice due dates in the future for `unpaid`, in the past for `overdue`, and let `effectiveStatus` compute the state; do not manually set `status = overdue` with a future date.

This principle applies to every new feature that touches shared state. When a test needs a member in a particular state, the test should build that state through the same flow a staff member would use in the app.
