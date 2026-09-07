# Currency Formatting Follow-Ups

Deliberately deferred during the currency-lock feature (per owner decision): the currency *selection* is being built, but the formatting and storage hardening below is out of scope. Each item is an independent future task with its own review — do not bundle them as drive-bys.

## Zero-Decimal Formatting

`Currency::format()` (`app/Support/Billing/Currency.php:23-26`) hardcodes precision `0` in `Number::currency($value, $code, null, 0)`, so every currency renders with no fraction digits. Correct for some currencies, wrong for others with minor units in active use. A future change could resolve fraction digits per currency (e.g. ISO 4217 minor units) instead of the hardcoded `0` — but every snapshot assertion on formatted money in the suite would need re-verification in the same change.

## English-Only Currency Symbols

`Currency::symbol()` (`app/Support/Billing/Currency.php:30-35`) builds `NumberFormatter` with a hardcoded `'en'` locale, so symbols ignore the `ar/es/fa/fr` app locale. Amounts still format in the app locale (`Number::currency` receives `null` locale); only the symbol lookup is English-pinned.

## Formatter Bypasses

Two render paths concatenate the symbol with a manually rounded number instead of going through `Number::currency`, losing locale position/separators: the signup verify overlay (`resources/views/filament/pages/partials/verify-overlay.blade.php`, `getCurrencySymbol()` + `number_format(..., 2)`) and the plan option label (`app/Filament/Resources/Subscriptions/Schemas/SubscriptionForm.php`, `getCurrencySymbol()` + `round()`). Both should call `Helpers::formatCurrency()` when touched.

## Mixed Precision and Float Storage

`expenses.amount` is `decimal(12,2)` while plans, invoices, and transactions store `float`, and rounding is scattered (`InvoiceCalculator` rounds to 0, `Discounts::amount` to 2, several bare `round()` calls). Unifying on exact decimal storage and one rounding rule is a migration-level change — never mix it into a display feature.

## Dead Admission Fee

`charges.admission_fee` is written by the Settings charges tab (`app/Filament/Pages/Settings.php`) but never read anywhere — it contributes to no fee, tax, or total. Either wire it into billing with its own review or remove the field; do not silently start charging it as part of another task.

## Rupee Tab Icon

The Settings charges tab uses `heroicon-m-currency-rupee` (`app/Filament/Pages/Settings.php`). Cosmetic only — swap for a neutral icon when convenient, no behavior change.
