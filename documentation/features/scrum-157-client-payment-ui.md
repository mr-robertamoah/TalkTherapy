# SCRUM-157 (TT-7.4b, 2/3): Client Pay button, redirect & return-flow status UI

Second of three sub-tickets splitting SCRUM-118 (see `documentation/decision-log.md`'s 2026-08-29
entries for the full history). Frontend half of SCRUM-110's Paystack backend: an actual "Pay Now"
action, a full-page redirect to Paystack's checkout, and a return-flow status banner. Depends on
SCRUM-156 (TT-7.4a), which exposed `paymentStatus`/`transactionStatus` on the backend. Individual
therapy and session only — group therapy is out of scope (TT-7.4d, blocked on a per-member payment
model that doesn't exist in the backend yet).

## What was built

- **`resources/js/Composables/usePayment.js`** (new) — owns the initiate/redirect/status/dismiss
  logic shared by both UI surfaces below:
  - `canPayForTherapy(isParticipant, isCounsellor)` / `canPayForSession(session, isParticipant,
    isCounsellor)` gate on `paymentType === 'PAID'`, the matching `per` (`PER_THERAPY` vs.
    `PER_SESSION`), `paymentStatus !== 'SUCCESS'`, and participant-not-counsellor. Both reuse
    `useTherapyState`'s existing `computedIsParticipant`/`computedIsCounsellor` (passed in, not
    recomputed) and both explicitly return `false` for group therapy.
  - `payForTherapy()`/`payForSession(session)` POST to the existing
    `transactions.initiate.therapy`/`.session` routes, then do a **real browser redirect**
    (`window.location.href = data.authorizationUrl`) — never Inertia's `router.visit`, since
    Paystack's checkout is off-domain.
  - `transactionStatus`/`statusBannerType`/`statusBannerMessage` read the `transactionStatus` flash
    prop SCRUM-156 wired through; `dismissStatus()` makes it a one-time banner within the visit
    (on top of Laravel's own flash-data aging, which already clears it across separate requests).
    "Abandoned"/"pending" are worded as recoverable, never a permanent failure.
- **`resources/js/Components/TherapyPaymentDetails.vue`** — "Pay Now" for the `PER_THERAPY` case,
  gated by two new props (`computedIsParticipant`, `computedIsCounsellor`) threaded down from
  `TherapyInformation.vue`. Shows "Paid" once `paymentStatus === 'SUCCESS'`. Own `useAlert()`/
  `<Alert>` for pay-initiation errors, matching this codebase's per-component-instance convention.
- **`resources/js/Pages/UnifiedTherapy.vue`** — "Pay Now" for the `PER_SESSION` case, added to the
  existing "Session Actions Modal" (only shown for the therapy's/group therapy's currently active
  session). A `watchEffect` surfaces the one-time `transactionStatus` banner via the page's own
  existing alert system.
- **`resources/js/Components/TherapyActiveHeader.vue`** — fixed a **pre-existing, unrelated bug**
  discovered while Playwright-testing this feature: the "show session information" toggle only
  emitted to a no-op stub, so the "Session Actions Modal" (start/end/abandon, not just the new Pay
  button) was unreachable through the UI for any user. See decision log.
- **`database/seeders/DatabaseSeeder.php`** — deterministic `payment_demo_client`/
  `payment_demo_counsellor` accounts with a `PER_THERAPY` and a `PER_SESSION` PAID therapy (plus one
  immediately-active seeded session), since the existing random demo therapies only *might* land on
  PAID and never deterministically pair the two payment models with a specific client/counsellor.

## Not yet built

- Group therapy Pay UI (TT-7.4d) — blocked on a per-member payment model.
- Counsellor read-only payment-status indicator (TT-7.4c) — separate, parallel sub-ticket.
- Retry-on-failure action — separate follow-up (carved out of the original TT-7.4 backlog item).
- This ticket doesn't add payment-gated session activation (TT-7.5, not yet landed) — a session's
  existing "payment will be made before session is activated" copy now has a reachable Pay action,
  but nothing yet blocks the session from activating unpaid.

## How to try it

Log in as the seeded client (`payment_demo_client` / `password`):

1. Visit the `PER_THERAPY` demo therapy ("Payment Demo Therapy (Per Therapy)") → "payment details"
   tab → "pay now". Calls the real initiate endpoint and redirects the full browser to Paystack's
   checkout URL.
2. Visit the `PER_SESSION` demo therapy ("Payment Demo Therapy (Per Session)") → double-click the
   expanded active-session panel (click "show session information" first) → "Session Actions" modal
   → "pay now" for its seeded, immediately-active session.
3. Log in as `payment_demo_counsellor` / `password` and visit the same `PER_THERAPY` therapy — no
   Pay control renders, only the price display.

**Local environment note**: this dev environment has no `PAYSTACK_SECRET_KEY` configured, so the
initiate call reaches the real endpoint but Paystack itself returns an auth error, surfaced as
"Unable to start the payment right now. Please try again shortly." (a real `TransactionException`
502, not a bug). The full checkout-and-return cycle needs valid Paystack sandbox credentials to
verify end-to-end; see "Testing performed" for how the return-flow banner and paid-state UI were
verified without them.

## Testing performed

- Full backend suite: 672 passed (unchanged — this ticket is frontend-only, plus one seeder
  addition). Whole-file Pint clean. Frontend production build (`npm run build`) clean.
- Playwright walkthrough (both seeded accounts):
  - Client sees "pay now" on the `PER_THERAPY` payment tab with the correct amount; clicking it
    correctly reaches the real endpoint and surfaces its 502 distinctly via the alert (proves the
    error-path wiring works, since this environment has no Paystack credentials to succeed against).
  - Manually flipping a `Transaction` to `SUCCESS` in the DB and reloading correctly shows "Paid"
    and hides the Pay button, for both the therapy-level and session-level surfaces.
  - Counsellor viewing the same therapy sees no Pay control under any status.
  - Session Actions Modal (`PER_SESSION` case) correctly shows/hides "pay now" the same way, after
    fixing the pre-existing broken toggle (see decision log).
- `reviewer` and `security-engineer` subagent review completed; no blocking findings from either.
  One reviewer suggestion applied (making the `PER_THERAPY` invariant explicit in
  `TherapyPaymentDetails.vue`'s "Paid" condition rather than relying on it implicitly).

## Files changed

- `resources/js/Composables/usePayment.js` (new)
- `resources/js/Components/TherapyPaymentDetails.vue`
- `resources/js/Components/TherapyInformation.vue`
- `resources/js/Components/TherapyActiveHeader.vue` (unrelated bug fix)
- `resources/js/Pages/UnifiedTherapy.vue`
- `database/seeders/DatabaseSeeder.php`
