# SCRUM-155 (TT-7.2c, 3/3): Counsellor pricing profile UI

Third and final sub-ticket splitting SCRUM-47 (see `documentation/decision-log.md`'s 2026-08-29
entries for the full history). Frontend for the pricing model built in TT-7.2b (SCRUM-154): a
counsellor-facing edit form and public display on the counsellor's profile.

## What was built

- **Edit form** (`resources/js/Components/UpdateCounsellorPricing.vue`), opened from a new
  "Pricing" section on `Profile/Counsellor/Show.vue` (via the same `ProfileEditButton` + modal
  pattern every other self-editable section on that page already uses):
  - A flat-vs-override mode toggle. Flat mode is amount + currency only, matching what
    `SetCounsellorPricingAction`/`EnsureCounsellorPricingDataIsValidAction` actually enforce
    server-side (an unscoped row) — not the `+ per` wording in this ticket's own AC #1, which
    conflicted with the schema description in the same ticket family; the already-merged,
    reviewed SCRUM-154 schema was treated as authoritative (see decision log).
  - Override mode: repeatable rows, each requiring a full `(therapyType, sessionType, per)`
    selection plus amount/currency before "save pricing" enables — matching the server's
    all-or-nothing validation client-side.
  - Currency select scoped to `config('currencies.supported')` (shared via Inertia, TT-7.2a's
    established convention), not free text.
  - Loads and pre-fills whatever the counsellor currently has configured (flat or override) when
    the modal opens.
  - A **"clear pricing"** action (only shown once pricing exists) — see "New backend capability"
    below for why this needed a small addition to the already-merged TT-7.2b backend.
- **Public display**: a new "Pricing" card on `Show.vue`, visible to any profile viewer including
  unauthenticated ones (same visibility tier as `about`/`profession`). Wording is explicitly
  informational ("Starting from" for flat, scope label + amount per override row) — never framed
  as a binding quote, per SCRUM-47's original informational-only product decision. A counsellor
  with nothing listed shows a clean empty state ("You have not listed pricing yet."), never `0`,
  `null`, or a broken value.
- **New backend capability** (`DELETE /counsellor/{counsellorId}/pricings`,
  `ClearCounsellorPricingAction`, `CounsellorPricingService::clearPricing()`): discovered while
  building the UI — the merged SCRUM-154 backend's `store()` always requires at least one pricing
  entry, so there was no way to represent "no pricing listed at all" once a counsellor had set
  one, but this ticket's own AC #6 explicitly requires a Playwright-verified "clear pricing"
  step. Same authorization action (`EnsureUserCanSetCounsellorPricingAction`) reused for both
  set and clear.

## How to try it

Log in as the seeded counsellor account (`sarah_johnson` / `password`, per
`documentation/seeded-data.md`), visit their own profile (`/counsellor/1`), and use the edit
button on the new "Pricing" section:

1. Set a flat rate (e.g. 150 GHS) → save → the profile immediately shows "Starting from GHS 150".
2. Re-open the edit form, switch to "per service", add one or more fully-specified overrides
   (e.g. Individual / Online / Per Session, 100 GHS) → save → the profile shows each override's
   scope and amount.
3. Re-open the edit form, click "clear pricing" → the profile returns to the empty state.

Visiting `/counsellor/1` while logged out (or as a different user) shows the same listed rate(s)
read-only, with no edit button.

## Not yet built

Nothing — this is the last of TT-7.2's 3 sub-tickets. TT-7.2 (SCRUM-47) is now fully implemented.

## Testing performed

- Full backend suite: 665 passed (up from 663). Whole-file Pint clean. Frontend production build
  (`npm run build`) clean.
- New backend tests: `tests/Unit/CounsellorPricingServiceTest.php` gained 2 tests for the new
  clear-pricing capability (self-service success, cross-counsellor rejection).
- Manual Playwright smoke-check (logged in as `sarah_johnson`) of the full golden path: set flat
  rate → verify on public (unauthenticated, cookie-less `curl` of the Inertia payload) profile →
  switch to override mode → verify on profile → clear pricing → verify empty state restored. No
  console errors/warnings at any step.
- `qa-engineer` ran an independent Playwright walkthrough — see decision log for findings.

## Files changed

- `resources/js/Components/UpdateCounsellorPricing.vue` (new) — edit form modal
- `resources/js/Pages/Profile/Counsellor/Show.vue` — new Pricing display section + edit button +
  modal instantiation
- `app/Actions/Counsellor/ClearCounsellorPricingAction.php` (new)
- `app/Services/CounsellorPricingService.php` — `clearPricing()` method
- `app/Http/Controllers/CounsellorPricingController.php` — `destroy()` method
- `routes/web.php` — `counsellor.pricings.destroy`
- `tests/Unit/CounsellorPricingServiceTest.php` — 2 new tests for clear-pricing
