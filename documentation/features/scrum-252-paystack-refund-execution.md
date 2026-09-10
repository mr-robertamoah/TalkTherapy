# TT-7.7d: Paystack Refund Execution (SCRUM-252)

Makes the real Paystack API call that actually moves money back to the client, once an admin has
approved a refund request (TT-7.7c). This is the highest-risk ticket in the TT-7.7 epic -- it's
the one that touches real money -- and has **no UI of its own**: it's triggered automatically by
the existing "approve" button built in TT-7.7c.

**No live Paystack sandbox credentials exist in this dev environment** (`.env.docker`'s
`PAYSTACK_SECRET_KEY` is empty). Every call this ticket makes to Paystack in this environment will
fail with a connection error -- see "What a successful result looks like" below for how to verify
this ticket's behavior anyway, and "Known limitation" for what can't be verified until real
credentials exist somewhere.

## What was built

- `App\Services\Paystack\PaystackClient::refundTransaction()` -- posts to Paystack's `POST /refund`
  endpoint. Unlike `initiateTransfer()`/`chargeAuthorization()`, this endpoint takes no
  caller-supplied idempotency reference; it keys entirely off the *original transaction's own*
  reference (confirmed via public docs research during this ticket's spike -- not empirically
  verified against a live sandbox).
- `App\Jobs\ProcessRefundJob` -- the queued job that makes this call, dispatched from
  `RespondToRefundRequestAction` strictly after its own DB transaction commits (never inline in
  the approve request/response cycle). Records a definite failure on a genuine 4xx, but rethrows
  (failing the job for a queue-driven retry, with backoff) on a 5xx or 429 -- those don't tell us
  whether Paystack actually processed the refund despite the error.
- `App\Actions\Transaction\RecordRefundStatusAction` -- the single choke point both this job's own
  synchronous Paystack response and a later `refund.processed`/`refund.failed` webhook call into,
  so a refund's status can never be regressed once terminal (`success`/`failed`), and identical
  calls are idempotent. On success for an org-financed transaction, triggers
  `ReconcileOrgFinancedRefundAction` (TT-7.3b-g) in the same DB transaction as the status write. On
  failure, notifies 2 random platform admins via the new `RefundExecutionFailedNotification`.
- A new `refund.*` branch in the existing `ProcessPaystackWebhookJob` (mirrors the `transfer.*`
  branch added in TT-7.6c) -- correlates a refund webhook back to our system via the *original
  transaction's* reference (`data.transaction.reference`/`data.transaction_reference`), then finds
  that transaction's one currently-active refund.
- `RefundStatusSourceEnum::initiate` -- distinguishes this job's own synchronous response from a
  later webhook in `refund_status_histories`, mirroring `CounsellorPayoutStatusSourceEnum::initiate`.

Deliberately **not** built here: any client-facing "your refund succeeded/failed" notification --
that's TT-7.7e's scope, since it needs the "your platform/therapy access is unaffected"
reassurance copy that belongs with that ticket's other outcome-notification work.

## How to try it out

### Test data

The seeded "Refund Demo Therapy (Pending Admin Review)" therapy (owned by `refund_demo_client`)
already has a `PENDING` refund request -- see `documentation/seeded-data.md`'s "Refund request UI
(SCRUM-250)" section and TT-7.7c's own feature doc for how to reach and approve it.

### Steps

1. Log in as the super admin (`mr_robertamoah`) and approve the seeded pending refund request at
   `/administrator/refund-requests` (see TT-7.7c's feature doc for the exact click-path).
2. Approving creates a `Refund` row (`pending`) and dispatches `ProcessRefundJob` -- check the
   `queue` container's logs (`docker compose logs -f queue`) to watch it run.
3. In this environment (no live Paystack credentials), the outbound HTTP call to Paystack will
   fail with a connection error -- `ProcessRefundJob` treats that the same as a 5xx (ambiguous,
   not a definite failure) and rethrows, so the job fails and retries per its `$backoff`
   (`[30, 120, 600]` seconds), up to the queue's configured `--tries`. The `Refund` row stays
   `pending` throughout -- this is the expected, correct behavior for "we don't know if it
   succeeded," not a bug.
4. To see a *recorded* outcome without live credentials, use `php artisan tinker` inside the `php`
   container to call `RecordRefundStatusAction` directly against the seeded refund, e.g.:
   ```php
   $refund = \App\Models\Refund::latest()->first();
   \App\Actions\Transaction\RecordRefundStatusAction::new()->execute(
       $refund, \App\Enums\RefundStatusEnum::success->value, \App\Enums\RefundStatusSourceEnum::initiate->value
   );
   ```

### What a successful result looks like

- After step 1: `Refund::count()` is 1 higher, status `pending`, with one `refund_status_histories`
  row (source `REQUESTED`).
- After the tinker call in step 4: the refund's status is `success`, a second status-history row
  exists (source `INITIATE`), and (if the underlying transaction is org-financed) the
  organization's affected `CounsellorEarning` rows are reversed.
- Running the same tinker call with `RefundStatusEnum::failed->value` instead notifies 2 random
  platform admins via email (Mailpit, `http://localhost:8025`) with subject "A Refund Could Not Be
  Processed."
- The full backend test suite (`docker compose exec php php artisan test --parallel`) covers all
  of the above end-to-end without needing live credentials, via `Http::fake()`:
  `tests/Unit/ProcessRefundJobTest.php`, `tests/Unit/RecordRefundStatusActionTest.php`, and the new
  `refund.*` cases in `tests/Feature/PaystackWebhookTest.php`.

### Known limitation

Whether Paystack's real refund endpoint actually rejects (4xx) a second `/refund` call against an
already-refunding transaction reference -- the assumption `ProcessRefundJob`'s retry-on-5xx logic
depends on for safety -- is unverified in this environment and should be confirmed against a real
Paystack sandbox before this code path runs against live credentials for the first time. A
follow-up ticket (SCRUM-255) also covers a related, lower-probability gap: a queue-redelivered job
could call Paystack a second time while a refund is still `processing` (not yet terminal).
