# SCRUM-215/TT-7.5a: Payment-gated access to individual Therapy/Session content

Implements TT-7.5a, the individual-Therapy/Session half of SCRUM-215/TT-7.5 (see
`documentation/decision-log.md`'s 2026-09-02 entries for the full planning history and the
product decisions this design is built on). TT-7.5b (GroupTherapy strict gating, SCRUM-216)
remains blocked on TT-7.4d (per-member group payment) and is not part of this feature.

Split across five sub-tickets, all merged into `develop`:

- SCRUM-217 (1/5): the `strictPaymentGate` setting itself
- SCRUM-218 (2/5): permanent, non-revocable first-access grant persistence
- SCRUM-219 (3/5): page-load enforcement for PER_THERAPY-payable therapies
- SCRUM-220 (4/5): session- and chat-level enforcement for PER_SESSION-payable therapies
- SCRUM-221 (5/5): counsellor toggle UI + client-facing payment-required banner

## What was built

**The setting.** `Therapy.payment_data->strictPaymentGate` (boolean) — a counsellor-controlled
per-therapy setting, stored in the existing `payment_data` JSON column alongside `per`/`amount`/
`currency`. Defaults to `false` (trust-based access, unchanged existing behavior). The paying
client sets its initial value at therapy-creation time (no counsellor exists yet at that point);
once a counsellor is assigned, only that assigned counsellor or an admin can change it —
never the client, even before a counsellor exists to hand it off to
(`EnsureCanSetStrictPaymentGateAction`).

**The grant.** `payment_access_grants` table: a permanent record of `(user_id, for_type, for_id,
transaction_id, granted_at)`, written once on a client's first successful payment
(`GrantPaymentAccessAction`, race-safe via `createOrFirst()`). Deliberately independent of
`Transaction.status` at read time — a later refund or chargeback does **not** retroactively lock
an already-admitted client out. This is a deliberate safety choice for a mental-health platform:
never abruptly sever an existing therapeutic relationship over a billing dispute.

**Enforcement.** `EnsureStrictPaymentGateSatisfiedAction` is the one shared payment check, applied
to whichever model actually carries the payment obligation:

- `PER_THERAPY`-payable therapies are gated at page load (`EnsureUserHasAccessToTherapyAction`,
  throws the new `PaymentRequiredException`, HTTP 402) and at every message/reply/topic access
  (`EnsureUserCanAccessTherapyContentAction`, consolidating `MessageService`'s previously-duplicated
  participant checks).
- `PER_SESSION`-payable therapies gate only the specific unpaid session, not the whole therapy/chat.

This only ever applies to the paying client (`$therapy->is_therapy && $therapy->addedby->is($user)`)
— counsellors, admins, guardians, and a therapy's own public/non-participant visibility are
completely unaffected.

**Frontend.** A small standalone toggle on the therapy payment-details panel, counsellor-only,
reachable via a dedicated `PATCH /therapies/{therapyId}/strict-payment-gate` endpoint (the general
`therapies.update` route cannot be used here — see "Bugs found" below). A matching checkbox on the
client's therapy-creation form sets the initial value. A blocked client sees a distinct
`PaymentRequiredBanner` on Home (not a generic error page) with a working "pay now" button, backed
by new `paymentRequired`/`paymentRequiredTherapyId`/`paymentRequiredMessage` Inertia props on
`HomeController::goHome()`.

## Bugs found and fixed during implementation

1. **Gating-logic bug (SCRUM-220)**: an early draft of `EnsureStrictPaymentGateSatisfiedAction`
   only checked PER_THERAPY gating when no `$session` was passed — but `MessageService::
   getSessionMessages()` always passes a session, so a PER_THERAPY-payable therapy was never
   gated for chat access at all. Fixed by checking PER_THERAPY unconditionally, before considering
   session presence.
2. **`ERR_TOO_MANY_REDIRECTS` (SCRUM-221)**: the counsellor toggle initially used plain
   `axios.patch()` against a route returning `Redirect::back()` (302). Fixed by switching to
   Inertia's `router.patch()`.
3. **Assigned counsellor couldn't reach the setting at all (SCRUM-221)**: `EnsureCanUpdateTherapyAction`
   has no branch recognizing a counsellor merely *assigned* via `counsellor_id` (only the therapy's
   own `addedby`) — found via live Playwright testing, not a unit test. Fixed with an entirely
   separate route/controller/service method that bypasses `EnsureCanUpdateTherapyAction` and relies
   solely on `EnsureCanSetStrictPaymentGateAction`'s own, self-contained authorization.
4. **Security: authorization-ordering bug (SCRUM-219/221)**: `EnsureCanSetStrictPaymentGateAction`
   originally short-circuited on "value unchanged" *before* checking identity, letting any
   authenticated user "set" an arbitrary therapy's gate to its current value and succeed — an
   unauthorized write plus a boolean oracle (success vs. 422 reveals the therapy's current setting
   to a caller with no relationship to it). Found by security-engineer review. Reordering the checks
   was insufficient (the equality check was still a separate, unconditionally-reachable early
   return); the fix removes the equality short-circuit entirely.

## Not yet built

TT-7.5b (GroupTherapy strict gating, SCRUM-216) — blocked on TT-7.4d (per-member group-therapy
payment), which is unscoped. Today's "first payer covers the whole group" model doesn't support a
meaningful per-member gate. SCRUM-215 stays open (In Progress) until TT-7.5b is unblocked and done.

## Test data

Uses the existing `payment_demo_client` / `payment_demo_counsellor` accounts (password `password`,
seeded in SCRUM-157 — see `documentation/seeded-data.md`'s "Payment UI" section), owning/assigned
to "Payment Demo Therapy (Per Therapy)" (USD 150, PER_THERAPY) and "Payment Demo Therapy (Per
Session)" (USD 50, PER_SESSION). No new seed data was needed for this feature.

## How to try it

1. Log in as `payment_demo_counsellor` → open "Payment Demo Therapy (Per Therapy)" → payment
   details tab → enable "strict payment gate".
2. Log in as `payment_demo_client` → visiting that therapy now shows the payment-required banner
   on Home instead of the therapy page, with a working "pay now" button.
3. Complete payment (or simulate a `SUCCESS` transaction in tinker) → access is granted and a
   `payment_access_grants` row is created.
4. Manually flip that transaction to `FAILED` afterward and reload — access remains granted
   (proving the no-retroactive-revocation guarantee end-to-end).
5. Remember to disable the toggle again afterward so the seeded therapy returns to its default
   trust-based state for other features' demos.

## Testing performed

- Full Pest suite: 991 passed (parallel, 8 processes) — no regressions across the five tickets.
- Pint clean on every touched file (whole-file, not just diff lines).
- Frontend production build clean.
- Live Playwright golden-path QA end-to-end: toggle → client blocked with distinct banner →
  pay → access granted → access survives the transaction later being marked `FAILED`.
- `reviewer` and `security-engineer` subagent review on every sub-ticket; all findings applied
  (see "Bugs found" above for the two most significant).

## Files changed

Backend: `app/Actions/Therapy/{EnsureCanSetStrictPaymentGateAction,EnsureStrictPaymentGateSatisfiedAction,EnsureUserCanAccessTherapyContentAction}.php`,
`app/Actions/Payment/GrantPaymentAccessAction.php`, `app/Models/PaymentAccessGrant.php`,
`app/Exceptions/PaymentRequiredException.php`, `app/Http/Controllers/{TherapyController,HomeController}.php`,
`app/Services/{TherapyService,MessageService}.php`, `routes/web.php`,
`database/migrations/*_create_payment_access_grants_table.php`.

Frontend: `resources/js/Components/{IndividualTherapyFormModal,TherapyPaymentDetails,PaymentRequiredBanner}.vue`,
`resources/js/Composables/usePayment.js`, `resources/js/Pages/Home.vue`.

Tests: `tests/Feature/{UpdateStrictPaymentGateTest,HomePaymentRequiredPropsTest}.php` plus unit
tests for each new Action.
