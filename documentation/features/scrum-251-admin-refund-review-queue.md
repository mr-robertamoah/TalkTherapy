# TT-7.7c: Admin Refund Review Queue (SCRUM-251)

Lets a platform admin see and decide on pending refund requests (asked for via TT-7.7b). Approving
creates a `Refund` row (`pending` status) via TT-7.7a's existing `RespondToRefundRequestAction` --
the real Paystack call is still TT-7.7d's job, not built yet. Rejecting requires a reason and
notifies the client immediately (distinct from TT-7.7e's later "refund outcome" notification,
which only fires once TT-7.7d's Paystack call actually resolves).

## What was built

- `GET /administrator/refund-requests` (`administrator.refund_requests`), a dedicated admin page
  (not another `Admin.vue` dispatch-table tab, matching `Admin/Payouts.vue`/`Admin/OrganizationBilling.vue`'s
  own precedent) listing every pending refund request, paginated.
- `App\Actions\Request\GetPendingRefundRequestsForAdminAction` (+ its own
  `EnsureCanManageRefundRequestsAction` authorization gate) -- a dedicated query, not
  `RequestService::getRequests()`, since that method's `to`-scoping can never surface a refund
  request (its `to` is always `null` by design).
- Approve/reject themselves are **not** new endpoints -- the page's action buttons post directly
  to the already-existing generic `POST /requests/{requestId}` endpoint
  (`requests.respond`), already admin-authorized via `EnsureUserCanRespondToRequestAction`'s
  `isAdmin()` short-circuit.
- `App\Actions\Request\RespondToRefundRequestAction` (built in TT-7.7a) extended: rejecting a
  refund request now **requires** a reason (fails with a 422 otherwise), persists it to
  `Request.data.rejectionReason` (kept distinct from the client's own ask-time `data.reason`), and
  notifies the client immediately via the new `RefundRequestRejectedNotification`.
- New `App\Http\Resources\RefundRequestResource` -- used both for the admin queue listing and as
  `GetRequestResourceAction`'s new `RequestTypeEnum::refund` branch (previously fell through to a
  resource that assumed `from` was a `Counsellor`, producing nonsense for a refund request).
- Fixed an adjacent bug: `RequestResource::getFor()` (the generic `/requests` listing used by a
  client's own "my requests" view) had no branch for a `Transaction` `for` either -- a refund
  request there previously rendered garbage.

## How to try it out

### Test data

A dedicated seeded therapy ("Refund Demo Therapy (Pending Admin Review)", owned by
`refund_demo_client`) already has an already-`PENDING` refund request on it -- the admin queue is
reachable immediately, with no manual "submit a refund request first" step. See
`documentation/seeded-data.md`'s "Refund request UI (SCRUM-250)" section.

### Steps

1. Log in as the super admin (`mr_robertamoah`, see `documentation/seeded-data.md`'s "Super Admin"
   section).
2. Visit `/administrator`, click "refund requests" in the nav (alongside "payouts"/"organization
   billing"), or go directly to `/administrator/refund-requests`.
3. Each pending request shows the client, the transaction's amount/currency and subject
   (therapy/session name), the client's stated reason, and when it was requested.
4. Click "approve" -- the row disappears and a `Refund` row is created (`pending` status,
   visible via `App\Models\Refund`).
5. Click "reject" on another row -- a reason textarea appears. Submitting with fewer than 10
   characters shows a client-side error; a valid reason removes the row, and the client (Mailpit,
   `http://localhost:8025`) receives a "Your Refund Request Was Declined" email including the
   reason and an explicit statement that their platform/therapy access is unaffected.

### What a successful result looks like

- Approving: `Refund::count()` increases by 1; the underlying `Request` row's `status` becomes
  `ACCEPTED`.
- Rejecting: the `Request` row's `status` becomes `REJECTED`, `data.rejectionReason` is set
  (distinct from the client's own `data.reason`), and `RefundRequestRejectedNotification` is sent.
- A non-admin visiting `/administrator/refund-requests` is redirected home.
