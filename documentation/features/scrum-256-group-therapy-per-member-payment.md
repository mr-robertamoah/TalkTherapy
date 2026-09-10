# TT-7.4d: Group-Therapy Per-Member Payment (SCRUM-256)

Extends TT-7.4's individual-therapy payment UI to GroupTherapy, where -- unlike an individual
Therapy/Session -- a group can have several members each with their own transaction, refund
request, and payment status. Flat per-head pricing: every member pays the same listed amount,
independent of group size. Split into five sub-tickets (TT-7.4d-a through -e).

## What was built

- **TT-7.4d-a (SCRUM-258)** -- `TherapyTrait::latestTransactionFor(User $user)` (also added
  directly to `Session`, which doesn't use the trait): the viewer-scoped counterpart to the
  pre-existing `latestTransaction()` relation, which is "the group's latest transaction by ANY
  member" -- correct for a single-payer individual Therapy, meaningless once a GroupTherapy can
  have several. Feeds new `viewerPaymentStatus`/`transactionId`/`refundRequestStatus` fields on
  `GroupTherapyResource`, additive alongside the existing unscoped `paymentStatus`.
- **TT-7.4d-b (SCRUM-259)** -- lifted `usePayment.js`'s hardcoded `therapyType !== 'group'`
  exclusion, via a `viewerScopedPaymentStatus(entity)` helper that reads the right field for each
  shape. A group member now gets a real "pay now" control for their own share, correctly reflecting
  their own payment, not a co-member's. Also fixed a live routing defect found during Playwright
  QA: `payForTherapy()` was always posting to the individual-Therapy route regardless of type, even
  though a dedicated `transactions.initiate.group_therapy` route already existed.
- **TT-7.4d-c (SCRUM-260)** -- lifted the client refund-request control for group members. The
  refund pipeline (`RequestRefundAction`/`EnsureTransactionIsRefundEligibleAction`) needed no
  backend change (already transaction/user-scoped, not model-scoped) -- but implementing it
  surfaced two real gaps: `canRequestRefund()` was still reading an unscoped payment-status field,
  and neither `GroupTherapyResource` nor `SessionResource` exposed a viewer-scoped `refundStatus`
  for a group (would have left a refunded member stuck seeing "Paid" forever). Both fixed.
- **TT-7.4d-d (SCRUM-261)** -- a new counsellor-facing per-member payment roster
  (`GetGroupTherapyPaymentRosterAction` + `GroupTherapyResource.paymentRoster`), visible only to
  the group's own assigned counsellor. Eager-loads members and transactions in a fixed 2 queries
  regardless of member count (no N+1). A deliberate, scoped exception to this codebase's
  otherwise-universal anonymity rule for the group-level `anonymous` flag -- but a member's own,
  independent per-member anonymity opt-in (`group_therapy_user.anonymous`) is still respected: an
  anonymous member is masked on the roster while their payment status stays visible.
- **TT-7.4d-e (SCRUM-262)** -- closeout: a locking-in test confirming `GetPayableAmountAction`
  already reads `payment_data.amount` directly with no group-size-aware split logic (the flat
  per-head decision, by construction, not incidentally); an HTTP-level regression test proving two
  different members of the same group can each initiate a charge via the real route.

## Product decisions (SCRUM-256 scoping)

- **Flat per-head pricing** -- every member pays the full listed `payment_data.amount`; no split or
  discount based on group size.
- **Peer visibility**: counsellor + self only -- a member never sees another member's payment
  status, refund status, or refund-request status.
- **Anonymity exception scoped narrowly**: the counsellor's payment roster (TT-7.4d-d) pierces
  anonymity ONLY for the payment-status field, and ONLY the group's own assigned counsellor -- and
  additionally still respects a member's own separate, individual anonymity opt-in (see TT-7.4d-d's
  own decision-log entry for the full trail of that question, resolved with the user mid-review).
- **No aggregate/readiness abstraction built** (architect guidance) -- the roster ships as a flat
  per-member list only. A later feature can trivially derive "N of M paid" once needed.

## How to try it out

### Test data

See `documentation/seeded-data.md`'s "Group therapy per-member payment (SCRUM-259, TT-7.4d)"
section: `group_payment_demo_member_paid`/`group_payment_demo_member_unpaid`/
`group_payment_demo_counsellor` (all password `password`), and two seeded PAID groups (one
`PER_THERAPY`, one `PER_SESSION`).

### Steps

1. Log in as `group_payment_demo_member_unpaid`, open "Group Payment Demo (Per Therapy)" →
   "payment details" tab -- see a real "pay now" control. Clicking it reaches Paystack
   initialization (no live Paystack credentials exist in this dev environment, so it 502s at that
   point -- this is expected, not a bug, matching TT-7.7's own documented environment limitation).
2. Log in as `group_payment_demo_member_paid` instead -- see "Paid", plus a "request a refund"
   control. Submitting it creates a `PENDING` refund request and shows "Refund requested -- pending
   admin review." on reload.
3. Log in as `group_payment_demo_counsellor` -- see the per-member payment roster on the same
   "payment details" tab, listing both members with their own individual status.
4. Repeat steps 1-3 on "Group Payment Demo (Per Session)" via the session-actions modal (double-click
   the expanded active-session panel to open it) -- same per-member behavior, scoped per session
   rather than per group.

### What a successful result looks like

- A group member sees and can act on their own payment/refund status, never a co-member's.
- The group's own counsellor sees every member's status in one place; nobody else does.
- A member who opted into personal anonymity is masked by name on the roster, while their payment
  status still shows.
- The full backend test suite covers all of the above: `tests/Feature/PaymentStatusExposureTest.php`,
  `tests/Feature/TransactionControllerRequestRefundTest.php`,
  `tests/Feature/GroupTherapyPaymentRosterTest.php`,
  `tests/Feature/GroupTherapyPaymentEpicRegressionTest.php`,
  `tests/Unit/EnsureCanInitiateChargeActionGroupTherapyTest.php`,
  `tests/Unit/GetGroupTherapyPaymentRosterActionTest.php`, and
  `tests/Unit/GetPayableAmountActionTest.php`.

## Epic complete

This closes out SCRUM-256 (TT-7.4d) -- see `documentation/decision-log.md` for the full trail of
judgment calls made across all five sub-tickets (TT-7.4d-a–e), including the live authorization bug
(SCRUM-257) found and fixed separately during this epic's own scoping pass, and the mid-review
anonymity-scope question resolved directly with the user rather than assumed.
