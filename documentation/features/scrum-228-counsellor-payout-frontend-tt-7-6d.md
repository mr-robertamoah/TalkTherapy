# SCRUM-228/TT-7.6d: Counsellor-facing payout frontend

The fourth of five sub-tickets in the Counsellor Payout epic (TT-7.6). Adds the first UI on top of
the three backend-only sub-tickets that preceded it:

- SCRUM-225 (TT-7.6a): payout-destination onboarding (bank/mobile-money, via Paystack)
- SCRUM-226 (TT-7.6b): earnings ledger + platform settings (fee %, minimum payout)
- SCRUM-227 (TT-7.6c): payout execution via Paystack Transfers

SCRUM-229 (TT-7.6e: admin-facing platform-fee-setting form, admin payout-trigger UI, payout audit
table) is the epic's remaining sub-ticket.

## What was built

**Backend (HTTP layer over the existing, already-reviewed Actions/Services)**

- `PayoutDestinationRequest` — validates `type`/`accountNumber`/`bankCode`/`currency` shape only;
  real authorization (verified counsellor) stays in `EnsureCanOnboardPayoutDestinationAction`,
  matching this codebase's thin-FormRequest convention.
- `PayoutController::onboardDestination()` / `triggerPayout()` — both build their DTO's `user`
  field strictly from `$request->user()`, never request input (a security requirement carried
  forward from TT-7.6a's own review). `triggerPayout()` accepts an optional `counsellorId` in the
  body for TT-7.6e's future admin-on-behalf-of use; `GetPayoutTargetCounsellorAction` (already
  reviewed in SCRUM-227) only honors it for an admin caller — a non-admin supplying someone else's
  `counsellorId` silently falls back to acting on their own counsellor's balance.
- Two routes, both self-service only (no `{counsellorId}` route param):
  `POST /payout-destination` (`payout.destination.store`, `throttle:30,1`) and
  `POST /payout/trigger` (`payout.trigger`, `throttle:6,1` — tighter, since it moves real money).
- `GetCounsellorPayoutOverviewAction` — read-only aggregation (payout destination, pending
  earnings in the destination's currency, available balance, minimum payout threshold, last 10
  payouts) merged into `CounsellorService::getCounsellorData()`, which `CounsellorController::
  show()` only calls inside its existing `$counsellor->user->is($request->user())` gate — private
  financial data is never present in another viewer's page props for the same profile URL, not
  just hidden behind Vue's `v-if`.
- Three new `Http\Resources` (`CounsellorEarningResource`, `CounsellorPayoutAccountResource`,
  `CounsellorPayoutResource`) and two read-only `Counsellor` relations (`earnings()`, `payouts()`).

**Frontend**

- `UpdateCounsellorPayoutDestination.vue` — a modal mirroring `UpdateCounsellorPricing.vue`'s
  structure (Modal + `useForm` + `FormLoader`/`Alert` composables), for setting up or replacing a
  bank/mobile-money destination.
- A new private "Payouts" section on `Profile/Counsellor/Show.vue` (gated `v-if="isCounsellor"`,
  placed just above the existing "Delete" section): payout destination status, available balance
  with an itemized gross/fee/net breakdown per pending earning, a withdraw button (disabled with a
  specific reason when no destination exists, a payout is already in progress, or the balance is
  below the seeded minimum), and payout history. A failed payout gets the same amber "try payout
  again" styling/copy convention as a failed therapy payment (TT-7.4-retry/SCRUM-222) — reusing the
  *convention*, not `usePayment`'s `isRetryStatus` helper itself, since `CounsellorPayoutStatusEnum`
  has no `ABANDONED`-equivalent state.
- **Money-formatting note**: `CounsellorEarning`/`CounsellorPayout` amounts are minor units
  (pesewas/cents, same as `Transaction.amount`), unlike `CounsellorPricing`/`paymentData` amounts
  which are already major units at the source — the frontend's `formatMoney()` divides by 100 for
  these fields specifically; this is a new frontend convention, not an existing one being reused
  (verified — no prior frontend code divided a money amount by 100 before this ticket).

## Test data

New seed data — see `documentation/seeded-data.md`'s "Counsellor payout (SCRUM-228)" section:
`payout_demo_counsellor` (password `password`) has two pending `CounsellorEarning` rows in GHS
(net GHS 81.00 + GHS 54.00 = GHS 135.00, above the seeded GHS minimum) but no payout destination
yet, so the onboarding-then-withdraw golden path is reachable immediately after seeding.

## How to try it

1. `docker compose exec php php artisan migrate:fresh --seed`
2. Log in as `payout_demo_counsellor` (password `password`) → visit their own counsellor profile
   → the new "Payouts" section shows the GHS 135.00 balance with its itemized breakdown and "No
   payout destination set up yet."
3. Click "edit" → fill in a destination (type, bank/network code, account number, currency) →
   save. **Note**: without a real `PAYSTACK_SECRET_KEY` configured in `.env.docker` (see
   `documentation/features/scrum-110-paystack-payments.md`), this call to Paystack's bank-resolve
   API will fail and surface "Could not verify that account number..." — this is the correct
   error-handling path, not a bug; configure test-mode Paystack keys to see the success path.
4. Once a destination exists and the balance is above the minimum, "withdraw" becomes enabled.
   Clicking it creates a `CounsellorPayout` (status `PENDING`), zeroes the visible available
   balance, and queues `ProcessCounsellorPayoutJob` to call Paystack's Transfer API.
5. Without real Paystack credentials, that job will mark the payout `FAILED` (a definite 4xx, not
   a transient 5xx — see SCRUM-227's own doc for that distinction) and revert the earnings back to
   `PENDING`. Reloading the profile then shows the amber "try payout again" button and the failed
   entry in Payout History.

## Testing performed

- Full Pest suite: 1059 passed (parallel, 8 processes) — no regressions.
- Pint clean on every touched/new file (whole-file, not just diff lines).
- Frontend production build clean.
- Live Playwright golden-path QA end-to-end, including manually processing the queued job to
  observe both the destination-onboarding error path (no real Paystack key) and the full
  trigger → pending → failed → retry-styling sequence described above.
- `reviewer` and `security-engineer` subagent review on the full diff; findings applied — see
  `documentation/decision-log.md`'s SCRUM-228 entry.

## Files changed

Backend: `app/Http/Requests/PayoutDestinationRequest.php`,
`app/Http/Resources/{CounsellorEarningResource,CounsellorPayoutAccountResource,CounsellorPayoutResource}.php`,
`app/Actions/Payout/GetCounsellorPayoutOverviewAction.php`, `app/Http/Controllers/PayoutController.php`,
`app/Models/Counsellor.php`, `app/Services/CounsellorService.php`,
`app/Http/Controllers/CounsellorController.php`, `routes/web.php`,
`database/seeders/DatabaseSeeder.php`.

Frontend: `resources/js/Components/UpdateCounsellorPayoutDestination.vue`,
`resources/js/Pages/Profile/Counsellor/Show.vue`.

Tests: `tests/Feature/PayoutControllerTest.php`, `tests/Unit/GetCounsellorPayoutOverviewActionTest.php`.
