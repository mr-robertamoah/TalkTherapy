# TT-7.7e: Refund Client/Counsellor Outcome Notifications (SCRUM-253)

The final sub-ticket in the TT-7.7 refund epic. Closes the loop TT-7.7d's real Paystack refund
execution left open: the client who asked for a refund now hears the outcome directly, and the
counsellor-facing payment-status indicator (TT-7.4c) stops showing a stale "Paid" once a refund
has actually gone through.

## What was built

- `App\Notifications\RefundSucceededNotification` / `RefundFailedNotification` — sent to the
  requesting client from `RecordRefundStatusAction`'s existing success/failed branches (inside the
  same locked DB transaction TT-7.7d's own security review already hardened). Both explicitly state
  "Your access to the platform and your therapy is completely unaffected" — a hard,
  product-owner-mandated requirement for this ticket, since a client must never wonder whether a
  refund means losing access to their counsellor. `RefundFailedNotification` is deliberately
  generic (no gateway-response detail) — that's the existing admin-only
  `RefundExecutionFailedNotification`'s job.
- `Transaction::successfulRefund()` — a new `hasOne` relation (at most one can ever exist, per
  `EnsureTransactionIsRefundEligibleAction`'s own invariant), feeding a new `refundStatus` field on
  `TherapyResource` and (individual-Therapy sessions only — see below) `SessionResource`.
- Frontend: `TherapyPaymentDetails.vue` and `UnifiedTherapy.vue` now show "Refunded" instead of
  "Paid" once `refundStatus === 'SUCCESS'` — `paymentStatus` itself never changes on refund (refunds
  live in their own table, per TT-7.7a's own architectural decision), so without this the label
  would stay "Paid" forever. Also fixed `usePayment.js`'s `canRequestRefund()` (found via manual
  Playwright QA): it didn't check `refundStatus`, so "request a refund" kept showing even after a
  refund had already succeeded.
- **Security-engineer finding, applied**: `refundStatus` is exposed unscoped on `TherapyResource`
  (safe — an individual Therapy has exactly one payer, same reasoning as the existing `paymentStatus`
  field) but is deliberately withheld (`null`) for a GroupTherapy session on `SessionResource` —
  that resource is shared with GroupTherapy, where `latestTransaction` can belong to a *different*
  member entirely, and refunds have no ask/admin-queue path for GroupTherapy anywhere in this epic.
- **Reviewer finding, applied**: eager-loads `latestTransaction.successfulRefund` in both bulk
  `SessionResource` render paths (`GetCounsellorCalendarSessionsAction`, `TherapyController::show`'s
  `recentSessions`) to avoid an N+1 per session.

## How to try it out

### Test data

The seeded "Refund Demo Therapy (Refunded)" therapy (owned by `refund_demo_client`, assigned to
`refund_demo_counsellor`) already has a `SUCCESS` `Refund` row — see
`documentation/seeded-data.md`'s "Refund request UI (SCRUM-250)" section.

### Steps

1. Log in as `refund_demo_client` or `refund_demo_counsellor` and open "Refund Demo Therapy
   (Refunded)" → "payment details" tab.
2. Both accounts see "Refunded" (not "Paid"), and neither sees a "request a refund" control.
3. To see the outcome notifications fire live, use `php artisan tinker` (no live Paystack
   credentials exist in this dev environment — see TT-7.7d's own feature doc):
   ```php
   $refund = \App\Models\Refund::factory()->create([
       'transaction_id' => \App\Models\Transaction::factory()->create([
           'for_type' => \App\Models\Therapy::class,
           'for_id' => \App\Models\Therapy::first()->id,
       ])->id,
   ]);
   \App\Actions\Transaction\RecordRefundStatusAction::new()->execute(
       $refund, \App\Enums\RefundStatusEnum::success->value, \App\Enums\RefundStatusSourceEnum::initiate->value
   );
   ```
   Check Mailpit (`http://localhost:8025`) for the "Your Refund Has Been Processed" email to the
   refund's `requestedBy`. Re-run with `RefundStatusEnum::failed->value` for "We Could Not Process
   Your Refund".

### What a successful result looks like

- The client and counsellor both see "Refunded" on an already-refunded therapy's payment details
  tab, and neither is offered "request a refund" again.
- `RecordRefundStatusAction::execute(..., success)` sends `RefundSucceededNotification` to the
  refund's `requestedBy`, in addition to (unchanged from TT-7.7d) triggering org-financed
  reconciliation when applicable.
- `RecordRefundStatusAction::execute(..., failed)` sends `RefundFailedNotification` to the client
  AND (unchanged from TT-7.7d) `RefundExecutionFailedNotification` to 2 random admins — two
  distinct notifications, one per audience.
- The full backend test suite covers all of the above without needing live Mailpit/Paystack
  access: `tests/Unit/RecordRefundStatusActionTest.php`'s new cases, `tests/Feature/PaymentStatusExposureTest.php`'s
  new `refundStatus` cases (including the GroupTherapy non-disclosure regression test), and the
  extended N+1 regression test in `tests/Feature/CounsellorCalendarSessionsTest.php`.

## Epic complete

This closes out SCRUM-223 (TT-7.7) — see `documentation/decision-log.md` for the full trail of
judgment calls made across all five sub-tickets (TT-7.7a–e).
