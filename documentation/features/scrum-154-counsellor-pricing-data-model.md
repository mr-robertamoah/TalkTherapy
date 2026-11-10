# SCRUM-154 (TT-7.2b, 2/3): Counsellor pricing data model + API

Second of 3 sub-tickets splitting SCRUM-47 (see `documentation/decision-log.md`'s 2026-08-29
entries for the full re-scoping history). Introduces a counsellor-controlled, client-facing
"preferred pricing" concept — a new `counsellor_pricings` table, entirely separate from the
existing `Therapy`/`GroupTherapy.payment_data` a client fills in at booking time.

## What was built

- New `counsellor_pricings` table (`counsellor_id`, nullable `therapy_type`/`session_type`/`per`,
  `amount`, `currency`). A counsellor is in exactly one of two modes: **one flat row** (all three
  scope columns null), or **N override rows**, each **fully specifying all three** scope
  dimensions — never a partial row, never both modes mixed in the same save.
- New `TherapyTypeEnum` (`INDIVIDUAL`/`GROUP`) — nothing existing covered this distinction, since
  `Therapy` and `GroupTherapy` are separate Eloquent models rather than a single model with a type
  column.
- `EnsureCounsellorPricingDataIsValidAction`: cross-row validation the FormRequest alone can't
  express — rejects mixing a flat row with overrides, more than one flat row, a partially-scoped
  override, and two overrides covering the identical scope (this last rule wasn't explicitly in
  the ticket text but is a natural data-integrity extension of "fully specifying all three" —
  otherwise two rows could silently disagree on the price for the same combination).
- `EnsureUserCanSetCounsellorPricingAction`: self-service only (`$dto->user->counsellor?->id ===
  $dto->counsellor->id`), plus a platform-admin bypass (`$dto->user->isAdmin()`) — no org-admin
  path, since this is the counsellor's own unilateral number, not something an org sets.
- `SetCounsellorPricingAction`: every save is a full delete-and-reinsert of the counsellor's
  `counsellor_pricings` rows in one DB transaction, not incremental upsert — avoids ever
  persisting an invalid in-between state.
- `POST /counsellor/{counsellorId}/pricings` (`CounsellorPricingController`), currency validated
  against `config('currencies.supported')` (TT-7.2a) in the FormRequest.
- `CounsellorResource` now exposes the current pricing set for public display.
- **Strictly informational** — `GetPayableAmountAction` (the one place a real charge amount is
  computed) carries an explicit guardrail comment; no code under `app/Actions/Transaction/` reads
  from `counsellor_pricings`, proven by a dedicated regression test. No link to
  `OrganizationCounsellorCompensationBasisEnum::COUNSELLOR_RATE` — stays unused.
- No versioning/history table, unlike `organization_counsellor_compensations` — there's no
  negotiation or accountability trail to reproduce for a unilateral, non-binding number.

## How to try it

Log in as a seeded counsellor account (`sarah_johnson` / `password`, per
`documentation/seeded-data.md`), then POST to `/counsellor/{counsellorId}/pricings` (their own
`counsellorId`) with either:

```json
// Flat rate
{"pricings": [{"amount": 150, "currency": "GHS"}]}

// Overrides (each fully specifying therapyType/sessionType/per)
{"pricings": [
  {"therapyType": "INDIVIDUAL", "sessionType": "ONLINE", "per": "PER_SESSION", "amount": 100, "currency": "GHS"},
  {"therapyType": "INDIVIDUAL", "sessionType": "IN_PERSON", "per": "PER_SESSION", "amount": 200, "currency": "GHS"}
]}
```

`GET`-ing that counsellor's profile (`CounsellorResource`, e.g. via `/counsellor/{counsellorId}`)
then shows the current `pricings` array. Setting a new configuration replaces whatever was there
before — no leftover rows from a prior mode.

## Not yet built (next sub-ticket)

TT-7.2c: the counsellor profile pricing UI (edit form + public display), full-ceremony with
Playwright QA.

## Testing performed

- New: `tests/Unit/CounsellorPricingServiceTest.php` (12 tests) — flat-rate set, override set,
  atomic replace, every cross-row rejection rule, self-service and admin-bypass authorization,
  counsellor-not-found, and the dedicated regression test proving `GetPayableAmountAction`'s
  computed amount for a real paid `Therapy` is completely unaffected by a wildly different listed
  pricing rate.
- Full suite: 639 passed (up from 627). Whole-file Pint clean.

## Files changed

- `database/migrations/2026_08_29_700000_create_counsellor_pricings_table.php` (new)
- `app/Enums/TherapyTypeEnum.php` (new)
- `app/Models/CounsellorPricing.php` (new), `app/Models/Counsellor.php` — `pricings()` relation
- `app/DTOs/CounsellorPricingDTO.php` (new)
- `app/Actions/Counsellor/EnsureCounsellorPricingDataIsValidAction.php`,
  `EnsureUserCanSetCounsellorPricingAction.php`, `SetCounsellorPricingAction.php` (all new)
- `app/Actions/Counsellor/EnsureCounsellorExistsAction.php` — widened to accept `CounsellorPricingDTO`
- `app/Services/CounsellorPricingService.php` (new)
- `app/Http/Requests/SetCounsellorPricingRequest.php`,
  `app/Http/Resources/CounsellorPricingResource.php`,
  `app/Http/Controllers/CounsellorPricingController.php` (all new)
- `app/Http/Resources/CounsellorResource.php` — exposes `pricings`
- `app/Actions/Transaction/GetPayableAmountAction.php` — guardrail comment only, no logic change
- `routes/web.php` — `counsellor.pricings.store`
- `database/factories/CounsellorPricingFactory.php` (new)
- `tests/Unit/CounsellorPricingServiceTest.php` (new)
