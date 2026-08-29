# SCRUM-158 (TT-7.4c, 3/3): Counsellor read-only payment-status indicator

Third and final sub-ticket splitting SCRUM-118 (see `documentation/decision-log.md`'s 2026-08-29
entries for the full history). A small follow-up to SCRUM-157 (client Pay Now UI): the counsellor
side of the same two surfaces. TT-7.4 (Payment UI) is now fully implemented — TT-7.4d (group
therapy) remains its own future epic, blocked on a per-member payment model.

## What was built

- **`resources/js/Composables/usePayment.js`** — a new pure `paymentStatusLabel(status)` function:
  `SUCCESS` → "Paid", `FAILED` → "Payment failed", anything else (`PENDING`/`ABANDONED`/`null`) →
  "Awaiting payment" — non-terminal wording, consistent with SCRUM-157's client-facing banner text.
- **`resources/js/Components/TherapyPaymentDetails.vue`** — a counsellor viewing a `PER_THERAPY`
  therapy that isn't yet `SUCCESS` now sees this label (styled red for "Payment failed", gray
  otherwise) instead of nothing. The existing "Paid" branch (already there from SCRUM-157) and the
  Pay-button branch are unchanged — this is a new, mutually-exclusive `v-else-if` branch.
- **`resources/js/Pages/UnifiedTherapy.vue`**'s Session Actions Modal — the same pattern for the
  `PER_SESSION` case.
- Both new branches explicitly exclude group therapy (`therapyType !== 'group'`), matching TT-7.4b's
  own scope exclusion — group-therapy payment status display is deferred to whenever TT-7.4d lands.

The Pay control's existing exclusion of counsellors (`canPayForTherapy`/`canPayForSession`'s
`!isCounsellor` check, unchanged from SCRUM-157) is what this ticket's read-only indicator sits
alongside — a counsellor was already correctly blocked from ever seeing the Pay button; this ticket
just gives them something to see instead of nothing.

## Not yet built

Nothing — TT-7.4 (SCRUM-118's payment UI) is now fully implemented for individual therapies and
sessions. Group therapy (TT-7.4d) and retry-on-failure UI remain separate, deferred follow-ups.

## How to try it

Log in as `payment_demo_counsellor` / `password` (seeded in SCRUM-157):

1. Visit "Payment Demo Therapy (Per Therapy)" → "payment details" tab → see "Awaiting payment"
   (no Pay control).
2. Visit "Payment Demo Therapy (Per Session)" → double-click the expanded active-session panel →
   "Session Actions" modal → see "Awaiting payment" (no Pay control).
3. Manually mark either therapy's/session's transaction `SUCCESS` in the database and reload — both
   surfaces switch to "Paid".

## Testing performed

- Full backend suite: 672 passed (unchanged — this ticket is frontend-only). Whole-file Pint
  n/a (no PHP changes). Frontend production build clean.
- Playwright walkthrough as `payment_demo_counsellor`: confirmed "Awaiting payment" on both
  surfaces for an unpaid therapy/session, and "Paid" on both after manually flipping a `Transaction`
  to `SUCCESS`; no Pay control rendered at any point.
- `reviewer` and `security-engineer` subagent review completed; both approved, no blocking findings.
  One reviewer suggestion applied (visually distinguishing "Payment failed" from "Awaiting payment"
  with red vs. gray text, matching this app's existing red/green status-color conventions).

## Files changed

- `resources/js/Composables/usePayment.js` — `paymentStatusLabel()`
- `resources/js/Components/TherapyPaymentDetails.vue` — counsellor status branch
- `resources/js/Pages/UnifiedTherapy.vue` — counsellor status branch (Session Actions Modal)
