# SCRUM-153 (TT-7.2a, 1/3): Platform-wide supported-currency list

First of 3 sub-tickets splitting SCRUM-47 (see `documentation/decision-log.md`, 2026-08-29 entry,
for the full re-scoping history — this started as a 3-point "counsellor pricing" stub and grew
into a 3-part feature once product-owner review found currency was unconstrained free-text
everywhere in the system, not just on the not-yet-built pricing field). This ticket lands first
since TT-7.2b (counsellor pricing) needs to validate against the same list, and it also absorbs
the currency-validation item previously (and incompletely) tracked under TT-7.4.

## What was built

- New `config/currencies.php`: `'supported'` is a comma-separated, env-overridable list
  (`SUPPORTED_CURRENCIES`, default `USD,GHS` — matching the two currencies already present in
  this codebase's data/fixtures). Changing the supported list is a one-line env change, not a
  code change.
- Retrofitted `currency` validation on all four places `Therapy`/`GroupTherapy` payment data is
  written: `CreateTherapyRequest`, `UpdateTherapyRequest`, `CreateGroupTherapyRequest`,
  `UpdateGroupTherapyRequest` — previously an unconstrained `'string'`, now
  `Rule::in(config('currencies.supported'))`.
- Defense-in-depth check in `EnsureCanInitiateChargeAction` (the existing gate run immediately
  before a Paystack charge is initiated): a therapy whose stored `payment_data->currency` is
  outside the supported list is rejected before ever reaching Paystack, even if that value was
  set through some path other than the four request classes above (a legacy row, a direct write).
- No schema change, no migration — `payment_data` is a JSON column, and existing dev data was
  audited (only `USD`/`GHS` values present) before this landed, so nothing needed backfilling.

## How to try it

Via tinker or the existing therapy-creation UI:

```php
// Rejected — outside the supported list:
App\Http\Requests\CreateTherapyRequest::create('/', 'POST', [
    'paymentType' => 'PAID', 'currency' => 'XYZ', /* ...other required fields... */
])->rules(); // 'currency' rule now includes Rule::in(['USD', 'GHS'])

// Change the supported list without touching code:
// .env: SUPPORTED_CURRENCIES=USD,GHS,EUR
```

Or via the existing "create therapy" UI flow: attempting to save with a currency outside
`config('currencies.supported')` now returns a validation error instead of silently accepting
any free-text value.

## Not yet built (next sub-tickets)

TT-7.2b (counsellor pricing data model, validates against this same list) and TT-7.2c (pricing
profile UI).

## Testing performed

- New: `tests/Unit/SupportedCurrencyValidationTest.php` (5 tests) — an unsupported currency is
  rejected on all 4 request classes; a supported currency passes on all 4; an omitted currency
  still passes on the nullable update requests; the list is genuinely config-driven (a currency
  that passes/fails flips when the config value itself is changed in the test, not hardcoded).
- New: a test in `tests/Unit/TransactionServiceTest.php` — a therapy with an unsupported stored
  currency (bypassing request validation entirely) is still rejected at charge-initiation time.
- Full suite: 626 passed (up from 620). Pint clean.

## Files changed

- `config/currencies.php` (new)
- `app/Http/Requests/CreateTherapyRequest.php`, `UpdateTherapyRequest.php`,
  `CreateGroupTherapyRequest.php`, `UpdateGroupTherapyRequest.php` — `currency` rule updated
- `app/Actions/Transaction/EnsureCanInitiateChargeAction.php` — defense-in-depth check added
- `tests/Unit/SupportedCurrencyValidationTest.php` (new)
- `tests/Unit/TransactionServiceTest.php` — new regression test
