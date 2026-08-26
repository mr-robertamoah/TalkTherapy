# SCRUM-110: Paystack payment integration (M1 — charge initiation, webhook, verify)

First real payment processing in the app. Before this, `payment_type`/`payment_data` on
`Therapy`/`GroupTherapy`/`Session` were pure descriptive metadata — "PAID" meant "labeled paid,"
not "paid for." This is the foundational M1 slice agreed during `/start-feature` planning:
single-currency charge initiation, webhook processing, and verification. Payment-gated access,
counsellor payout, refunds, and multi-currency support are all deliberately out of scope here —
see "Follow-ups" below.

**This is a backend-only change.** No Vue/Inertia UI was built in this ticket — there is no "Pay
now" button anywhere yet. Trying it out means calling the new endpoints directly (curl/Postman)
or via `tinker`, as described below.

## What was built

- **`Transaction` + `TransactionStatusHistory` models** (`app/Models/Transaction.php`,
  `app/Models/TransactionStatusHistory.php`) — a real, audit-trailed payment ledger, polymorphic
  via `for_type`/`for_id` (matching the existing `TherapyTrait`/`Session` `for` convention, not a
  new vocabulary). Every status change is recorded as its own history row with a source
  (`initiate`/`webhook`/`verify`), not just overwritten on a single column.
- **`TransactionService::initiateCharge()`** — validates the target is actually payable
  (`payment_type == PAID`, a price is set, the charge target matches the `per` setting:
  `PER_THERAPY` therapies can't be charged per-session and vice versa, not already paid for),
  checks the requesting user is actually a participant of the Therapy/GroupTherapy (and not its
  own assigned counsellor), then calls Paystack's Initialize Transaction endpoint and creates the
  `Transaction` row.
- **Webhook handling** (`POST /api/paystack/webhook`) — verifies Paystack's HMAC-SHA512
  signature against the raw request body, then defers the actual status-recording to a queued
  `ProcessPaystackWebhookJob` (so a slow database write can't make Paystack think delivery
  failed and retry). A job failure here is caught by SCRUM-82's existing admin-alerting.
- **Verify-callback fallback** (`GET /transactions/callback`) — the route Paystack redirects the
  browser back to after checkout; calls Paystack's Verify Transaction endpoint directly, for
  when webhook delivery is delayed or missed. Scoped to the requesting user (can't verify someone
  else's transaction reference).
- **`PaystackClient`** (`app/Services/Paystack/PaystackClient.php`) — this app's first outbound
  third-party HTTP integration, a thin wrapper around Laravel's HTTP client.
- Idempotency: delivering the same event twice, or a later event arriving after a transaction is
  already `success`/`failed` (out-of-order delivery, or the webhook racing the verify-callback),
  never double-records or regresses a completed transaction.

## Try it out

### 1. Configure test-mode Paystack keys

Get test-mode keys from your Paystack dashboard and set them in `.env.docker` (or `.env` if
running outside Docker):

```
PAYSTACK_SECRET_KEY=sk_test_...
PAYSTACK_PUBLIC_KEY=pk_test_...
```

Restart the `php`/`queue` containers (or `php artisan config:clear`) after changing these.

### 2. Create a PAID therapy to test against

The seeded demo data (`documentation/seeded-data.md`) includes some randomly-PAID therapies, but
their `payment_data.per` value doesn't reliably match this feature's expected enum value, so
create one explicitly instead — easiest via `tinker`:

```bash
docker compose exec php php artisan tinker
```

```php
$user = \App\Models\User::first(); // any seeded demo user, e.g. mr_robertamoah's therapies owner
$therapy = \App\Models\Therapy::factory()->create([
    'addedby_type' => \App\Models\User::class,
    'addedby_id' => $user->id,
    'payment_type' => 'PAID',
    'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS'],
]);
$therapy->id; // note this for the next step
```

### 3. Initiate a charge

As that same user (log in via the UI, or grab their session cookie / use Sanctum if testing via
API client), POST to:

```
POST /therapies/{id}/transactions
```

Response: `{"transaction": {...}, "authorizationUrl": "https://checkout.paystack.com/..."}`.
Opening `authorizationUrl` in a browser lets you complete a real test-mode checkout against
Paystack's sandbox (use any of Paystack's published test card numbers).

### 4. Confirm the transaction resolves

Either:
- Complete checkout in the browser — Paystack redirects to `/transactions/callback`, which
  verifies and redirects to the therapy's page; or
- Point a webhook tool (e.g. `ngrok` + the Paystack dashboard's test webhook sender, or `curl`
  with a manually-computed signature) at `/api/paystack/webhook` with a `charge.success` payload
  for that reference.

Check the result: `Transaction::where('reference', '...')->first()->status` should be `SUCCESS`,
and `->statusHistories` should show the full trail.

### Automated verification (no real Paystack account needed)

The full flow (initiate, webhook success/failure/duplicate-delivery, verify, and all the
authorization/idempotency edge cases) is exercised end-to-end against a faked Paystack client in
the test suite — this is the fastest way to see the feature working without any real credentials:

```bash
docker compose exec php php artisan test --filter "TransactionServiceTest|PaystackWebhookTest|InitiateTransactionTest"
```

## Test data

No new seeder changes were made for this ticket — see "Try it out" above for why (seeded PAID
therapies exist but aren't reliably shaped for this feature's `per`/`amount`/`currency`
expectations; creating one explicitly via `tinker` is more reliable than depending on random
seed output).

## Follow-ups (deliberately deferred, not silently dropped)

- **SCRUM-116** (High, security): the same client-overridable route-parameter pattern this
  ticket fixed in `TransactionController` also exists in `SessionController`/`TherapyController`/
  `GroupTherapyController`.
- **SCRUM-117** (Medium): webhook/verify doesn't cross-check the reported amount/currency against
  the stored `Transaction` before marking it `success`.
- **TT-7.4** — retry-on-failure UI, real currency validation (replacing free-text).
- **TT-7.5** — payment-gated access: a per-therapy, counsellor-controlled setting for whether
  access is hard-gated on payment or tracked on trust (decision already made, not yet built).
- **TT-7.6** — counsellor payout via Paystack Transfers (its own Epic-sized item; needs
  bank-account/KYC onboarding).
- **TT-7.7** — refund handling.
- **TT-7.9** — multi-currency support with a disclosed forex markup.

See `documentation/implementation_plan.md`'s Epic TT-7 table for the full breakdown.
