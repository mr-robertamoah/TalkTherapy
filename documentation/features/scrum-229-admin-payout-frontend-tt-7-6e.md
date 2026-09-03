# SCRUM-229/TT-7.6e: Admin-facing payout frontend

The fifth and final sub-ticket in the Counsellor Payout epic (TT-7.6), completing it. Sits on top
of the four already-merged sub-tickets:

- SCRUM-225 (TT-7.6a): payout-destination onboarding
- SCRUM-226 (TT-7.6b): earnings ledger + platform settings mechanism
- SCRUM-227 (TT-7.6c): payout execution via Paystack Transfers
- SCRUM-228 (TT-7.6d): counsellor-facing frontend

## What was built

A new, dedicated `resources/js/Pages/Admin/Payouts.vue` page (reachable via a small nav link added
to `Admin.vue`, at `/administrator/payouts`) — deliberately not another tab in `Admin.vue`'s
existing dispatch-table pattern, which `documentation/implementation_plan.md` already flags as
ad-hoc debt (see `documentation/decision-log.md`'s 2026-09-03 entry for the full rationale).

**Platform Settings** — two independent forms, both reading/writing TT-7.6b's `platform_settings`
mechanism (not a hardcoded value): the platform fee percentage, and the minimum payout amount for
every supported currency at once. The minimum-payout form always submits the full currency set
together (never a partial subset), since `SettingsEnum::minimumPayoutAmount` is stored as one JSON
blob per key — a partial or duplicate-currency submission would otherwise silently drop a
currency's threshold back to its env-config default (`UpdateMinimumPayoutAmountsRequest` rejects
both cases). Both forms' actual authorization (super admin only) lives in the already-existing
`UpdateSettingAction`/`EnsureIsSuperAdminAction`, unchanged from TT-7.6b — the new FormRequests are
thin, matching this codebase's established convention.

**Trigger Payout** — a debounced counsellor search (reusing the existing `admin.counsellors`
endpoint from `AdministratorController`), then a balance-overview fetch for the selected
counsellor (a new `GET /administrator/payouts/counsellors/{counsellorId}/overview` endpoint,
independently admin-gated via `EnsureIsAdminAction`, reusing TT-7.6d's `GetCounsellorPayoutOverviewAction`
directly), then a trigger button that reuses TT-7.6c/d's existing `payout.trigger` route with
`counsellorId` in the body — no new trigger endpoint was built; `GetPayoutTargetCounsellorAction`
already handles the admin-on-behalf-of resolution and enforces the identical minimum-payout
threshold as counsellor self-service (no admin bypass).

**Payout Audit History** — a paginated table (reusing the existing `Pagination.vue` component)
showing every payout across every counsellor: counsellor name, amount, status, who initiated it
(`'Self'` vs. the admin's name), reference, and — for a failed payout — the recorded failure
reason. Backed by a new `AdminCounsellorPayoutResource` (distinct from TT-7.6d's counsellor-facing
`CounsellorPayoutResource`, which never exposes another counsellor's identity or a failure
message) and a new `GET /administrator/payouts` JSON endpoint.

## Review findings applied

`security-engineer` found one real Medium-severity gap, both fixed before merging:

1. `UpdateMinimumPayoutAmountsRequest`'s `size:N` check didn't require N *distinct* currencies — a
   same-size payload repeating one currency would pass while silently dropping the omitted
   currency's threshold. Fixed by adding a `distinct` rule.
2. `PayoutService::getPayoutsForAdmin()` used a silent-empty-array convention for a non-admin
   caller, inconsistent with its own sibling method (`getPayoutOverviewForAdmin()`) and more
   fragile for this endpoint's sensitivity (payout data across every counsellor). Switched to a
   real thrown exception (`EnsureIsAdminAction`), matching its sibling.

Full rationale for both, plus the reviewer's (no-blocking-issue) pass, in
`documentation/decision-log.md`'s 2026-09-03 SCRUM-229 entry.

## Test data

No new seed data needed. Uses the existing super admin (`mr_robertamoah`, password from
`SUPER_PASSWORD` in `.env.docker`) and the `payout_demo_counsellor` account seeded in SCRUM-228
(see `documentation/seeded-data.md`'s "Counsellor payout (SCRUM-228)" section) — that account
already has pending earnings and (once onboarded) a payout destination, exercising this page's
counsellor-search/trigger flow end-to-end.

## How to try it

1. `docker compose exec php php artisan migrate:fresh --seed`
2. Log in as `mr_robertamoah` → visit `/administrator/payouts` (or click "payouts" in the top nav
   from `/administrator`).
3. Platform Settings: change the fee percentage or a currency's minimum, save, confirm the success
   alert and that the value persists on reload.
4. Trigger Payout: search "Payout Demo" → select `Dr. Payout DemoCounsellor` → see their balance.
   If no payout destination is set up yet, the trigger button is disabled with a clear reason
   (onboard one as that counsellor first, or via tinker for a quick manual check — see SCRUM-228's
   own feature doc). Once a destination exists and the balance clears the minimum, trigger the
   payout and watch it appear in Payout History as `pending`, with "Initiated By" showing the
   admin's own name (not "Self").
5. Without a real `PAYSTACK_SECRET_KEY` configured, the queued job will mark it `FAILED` shortly
   after — reload the page to see the failure reason in the audit table.

## Testing performed

- Full Pest suite: 1074 passed (parallel, 8 processes) — no regressions.
- Pint clean on every touched/new file (whole-file, not just diff lines).
- Frontend production build clean.
- Live Playwright golden-path QA end-to-end: platform-fee update, minimum-payout-amounts update,
  counsellor search/select, balance overview, triggering a payout as the admin, watching it land
  in the audit table as `pending` with the correct `initiatedBy`, then watching the queued job
  mark it `FAILED` with the failure reason appearing in the audit table.
- `reviewer` and `security-engineer` subagent review on the full diff; the one real finding
  (duplicate-currency validation gap) fixed, plus a related consistency hardening and a missing
  test — see `documentation/decision-log.md`.

## Files changed

Backend: `app/DTOs/GetCounsellorPayoutOverviewForAdminDTO.php`,
`app/Http/Requests/{UpdatePlatformFeeRequest,UpdateMinimumPayoutAmountsRequest}.php`,
`app/Http/Resources/AdminCounsellorPayoutResource.php`, `app/Services/{SettingsService,PayoutService}.php`,
`app/Http/Controllers/AdminPayoutController.php`, `routes/{web,api}.php`.

Frontend: `resources/js/Pages/Admin/Payouts.vue`, `resources/js/Pages/Admin.vue` (nav link only).

Tests: `tests/Feature/AdminPayoutControllerTest.php`, `tests/Unit/SettingsServiceTest.php` (extended).
