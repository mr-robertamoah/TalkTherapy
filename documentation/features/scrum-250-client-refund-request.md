# TT-7.7b: Client Refund Request (SCRUM-250)

Lets a client ask for a refund on a transaction they paid for, with a required reason. Builds on
TT-7.7a (SCRUM-249), which already added the `refunds`/`refund_status_histories` data model and
`EnsureTransactionIsRefundEligibleAction`.

Admin review/approval of the request (TT-7.7c) and the actual Paystack refund call (TT-7.7d) are
not part of this ticket -- this ticket only covers the client's own "ask."

## What was built

- `App\Actions\Transaction\RequestRefundAction`: validates the caller owns the transaction, that a
  reason is present, and that the transaction is refund-eligible (via TT-7.7a's own
  `EnsureTransactionIsRefundEligibleAction`), then creates a `refund`-type `Request` row via the
  existing polymorphic `CreateRequestAction`. Notifies 2 random platform admins
  (`RefundRequestedNotification`) -- refund requests have no single targeted admin the way other
  request types do.
- `POST /transactions/{transactionId}/refund-request` (`transactions.refund_request.store`),
  handled by `TransactionController::requestRefund()`, validated by `RequestRefundRequest` (reason
  required, 10-1000 characters).
- `TherapyResource`/`SessionResource` now expose `transactionId` and `refundRequestStatus` (null,
  `PENDING`, `ACCEPTED`, or `REJECTED`) so the frontend knows what to show.
- `Transaction::latestRefundRequest()`: a new `morphOne` relation (mirrors
  `Organization::latestFailedInvoice()`'s own `ofMany`-with-constraint shape) backing the resource
  fields above.
- Frontend: `usePayment.js` gained `canRequestRefund()`/`requestRefund()`, shared by both payment
  surfaces:
  - `TherapyPaymentDetails.vue` (PER_THERAPY) -- a "request a refund" button below the "Paid"
    label, expanding into a reason textarea + submit/cancel.
  - `UnifiedTherapy.vue`'s Session Actions modal (PER_SESSION) -- the same control, alongside the
    existing "paid" label.
  - A `REJECTED` prior request doesn't block asking again (eligibility only blocks on an
    active/pending refund or request) -- the UI shows "Your previous refund request was declined.
    You may request again below." and still offers the button.

## How to try it out

### Test data

Two seeded demo therapies with an already-`SUCCESS` transaction (see
`documentation/seeded-data.md`'s "Refund request UI (SCRUM-250)" section for the full table):

| Username | Password | Purpose |
|---|---|---|
| `refund_demo_client` | `password` | Owns both refund demo therapies. |
| `refund_demo_counsellor` | `password` | Assigned counsellor -- confirm no refund control ever renders for them. |

### Steps

1. Log in as `refund_demo_client`.
2. **PER_THERAPY**: visit "Refund Demo Therapy (Per Therapy)"'s "payment details" tab. Below
   "Paid," click "request a refund," enter a reason (10+ characters), submit. A success banner
   appears and the control switches to "Refund requested -- pending admin review."
3. **PER_SESSION**: visit "Refund Demo Therapy (Per Session)," click "show session information"
   then double-click the expanded green active-session panel to open "Session Actions." The same
   request-a-refund control appears there, alongside "paid."
4. Log in as `refund_demo_counsellor` and confirm neither payment-details tab nor session-actions
   modal ever shows a refund control for them.

### What a successful result looks like

- A new `Request` row (`type = REFUND_REQUEST`, `status = PENDING`) with the client as `from`, the
  transaction as `for`, and the reason in `data`.
- Two random platform admins are notified by email (Mailpit, `http://localhost:8025`) -- subject
  "A Client Has Requested a Refund."
- Requesting again on the SAME transaction while a request is still pending returns a 422
  ("A refund request for this transaction is already pending").
