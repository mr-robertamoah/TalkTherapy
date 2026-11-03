<?php

use App\Enums\RefundStatusEnum;
use App\Enums\RefundStatusSourceEnum;
use App\Jobs\ProcessRefundJob;
use App\Models\PaymentAccessGrant;
use App\Models\Refund;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

// TT-7.7d/SCRUM-252: mirrors ProcessCounsellorPayoutJobTest's own shape exactly -- this is the
// job that makes the real, external, money-moving Paystack call.

function aPendingRefundWithTransaction(): Refund
{
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);
    $transaction = Transaction::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'reference' => 'refund_job_txn_ref',
        'status' => 'SUCCESS',
    ]);

    return Refund::factory()->create([
        'transaction_id' => $transaction->id,
        'status' => RefundStatusEnum::pending->value,
    ]);
}

test('a synchronous processed response from Paystack records the refund as succeeded immediately', function () {
    Http::fake(['*/refund' => Http::response([
        'status' => true,
        'data' => ['status' => 'processed'],
    ], 200)]);
    $refund = aPendingRefundWithTransaction();

    ProcessRefundJob::dispatchSync($refund->id);

    expect($refund->fresh()->status)->toBe(RefundStatusEnum::success->value);
});

test('an asynchronous pending response leaves the refund processing, awaiting the webhook', function () {
    Http::fake(['*/refund' => Http::response([
        'status' => true,
        'data' => ['status' => 'pending'],
    ], 200)]);
    $refund = aPendingRefundWithTransaction();

    ProcessRefundJob::dispatchSync($refund->id);

    expect($refund->fresh()->status)->toBe(RefundStatusEnum::processing->value);
});

test('a 4xx (client error) response from Paystack records the refund as a definite failure', function () {
    Http::fake(['*/refund' => Http::response(['status' => false, 'message' => 'Transaction already refunded'], 400)]);
    $refund = aPendingRefundWithTransaction();

    ProcessRefundJob::dispatchSync($refund->id);

    expect($refund->fresh()->status)->toBe(RefundStatusEnum::failed->value);
});

// Mirrors ProcessCounsellorPayoutJobTest's identical reasoning: a 5xx means we genuinely don't
// know whether Paystack actually processed the refund despite the error -- recording a definite
// failure here would be a false "safe to retry/investigate" signal when the client may already
// have been refunded. This must fail the QUEUED JOB itself (so its own retry re-attempts against
// the SAME transaction reference), never the refund record.
test('a 5xx (server error) response from Paystack does not record a definite failure -- it fails the job for retry instead', function () {
    Http::fake(['*/refund' => Http::response(['status' => false, 'message' => 'Internal server error'], 500)]);
    $refund = aPendingRefundWithTransaction();

    expect(fn () => ProcessRefundJob::dispatchSync($refund->id))
        ->toThrow(RequestException::class);

    expect($refund->fresh()->status)->toBe(RefundStatusEnum::pending->value);
});

test('a retried job never calls Paystack again once the refund has already reached a terminal status', function () {
    Http::fake(); // any call here would be a bug -- no stub means the test fails loudly if reached.
    $refund = aPendingRefundWithTransaction();
    $refund->update(['status' => RefundStatusEnum::success->value]);

    ProcessRefundJob::dispatchSync($refund->id);

    Http::assertNothingSent();
});

// SCRUM-255 (security-engineer finding, HIGH, second pass): the WithoutOverlapping lock alone
// does NOT close this gap -- it releases the instant the first dispatch's handle() returns, which
// happens the moment an async response is recorded as `processing`. A redelivery arriving AFTER
// that (the common case for a live-mode async refund, not just a narrow race) would find the lock
// free; only checking for `pending` (not "any non-terminal status") stops it calling Paystack a
// second time. Deliberately does NOT hold any lock here -- proves the fix holds even when the
// earlier attempt has already fully finished and released it.
test('a redelivered dispatch after the first attempt already finished as processing never calls Paystack again', function () {
    Http::fake(['*/refund' => Http::response([
        'status' => true,
        'data' => ['status' => 'pending'],
    ], 200)]);
    $refund = aPendingRefundWithTransaction();

    ProcessRefundJob::dispatchSync($refund->id);
    expect($refund->fresh()->status)->toBe(RefundStatusEnum::processing->value);

    Http::fake(); // any call here would be a bug -- no stub means the test fails loudly if reached.
    ProcessRefundJob::dispatchSync($refund->id);

    Http::assertNothingSent();
    expect($refund->fresh()->status)->toBe(RefundStatusEnum::processing->value);
});

// SCRUM-255: closes the gap the terminal-status guard above doesn't cover -- a queue-redelivered
// dispatch (visibility-timeout mid-flight, or an operator's queue:retry) while ANOTHER attempt for
// the SAME refund is still actually in flight (not yet terminal). Simulated here by holding the
// WithoutOverlapping lock the job's own middleware() would acquire, exactly as a real in-flight
// attempt would.
test('a redelivered dispatch while another attempt for the same refund is in flight never calls Paystack', function () {
    Http::fake(); // any call here would be a bug -- no stub means the test fails loudly if reached.
    $refund = aPendingRefundWithTransaction();

    $lock = Cache::lock('laravel-queue-overlap:App\\Jobs\\ProcessRefundJob:'.$refund->id, 120);
    $lock->get();

    try {
        ProcessRefundJob::dispatchSync($refund->id);

        Http::assertNothingSent();
        expect($refund->fresh()->status)->toBe(RefundStatusEnum::pending->value);
    } finally {
        $lock->release();
    }
});

// Security-engineer finding: seeding the grant for a brand-new, unrelated Therapy/User (as this
// test originally did) would pass identically even if this isolation guarantee were entirely
// absent. Tying the grant to the SAME transaction/therapy the refund under test actually belongs
// to (via the refund's own transaction) makes this a meaningful regression test.
test('never reads or writes payment_access_grants', function () {
    Http::fake(['*/refund' => Http::response(['status' => true, 'data' => ['status' => 'processed']], 200)]);
    $refund = aPendingRefundWithTransaction();
    $transaction = $refund->transaction;
    $therapy = $transaction->for;
    $grant = PaymentAccessGrant::create([
        'user_id' => $therapy->addedby_id,
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'transaction_id' => $transaction->id,
        'granted_at' => now(),
    ]);

    ProcessRefundJob::dispatchSync($refund->id);

    test()->assertDatabaseCount('payment_access_grants', 1);
    expect($grant->fresh()->granted_at->equalTo($grant->granted_at))->toBeTrue();
});

// Security-engineer finding: a 429 (rate-limited) is not a real rejection signal from Paystack --
// must be treated like a 5xx (ambiguous, retry), never recorded as a definite failure.
test('a 429 (rate limited) response from Paystack does not record a definite failure -- it fails the job for retry instead', function () {
    Http::fake(['*/refund' => Http::response(['status' => false, 'message' => 'Too many requests'], 429)]);
    $refund = aPendingRefundWithTransaction();

    expect(fn () => ProcessRefundJob::dispatchSync($refund->id))
        ->toThrow(RequestException::class);

    expect($refund->fresh()->status)->toBe(RefundStatusEnum::pending->value);
});

// SCRUM-376/378: a refund can only be looked up by Paystack's own refund id. With none stored (the
// earlier attempt never got a response -- see ProcessRefundJob), a 4xx on a retry still rethrows and
// leaves the refund untouched for the webhook/follow-up, calling nothing else.
test('a retry refused with a 4xx still rethrows and leaves the refund pending, with no lookup', function () {
    Http::fake(['*/refund' => Http::response(['status' => false, 'message' => 'Transaction has been fully reversed'], 400)]);
    $refund = aPendingRefundWithTransaction();

    $job = (new ProcessRefundJob($refund->id))->withFakeQueueInteractions();
    $job->job->attempts = 2;

    expect(fn () => $job->handle())->toThrow(RequestException::class);

    expect($refund->fresh()->status)->toBe(RefundStatusEnum::pending->value);
    Http::assertSentCount(1);
});

// SCRUM-378: Paystack's refund id from the POST /refund response is what a retry's lookup needs.
test('the refund id Paystack returns is stored on the refund', function () {
    Http::fake(['*/refund' => Http::response(['status' => true, 'data' => ['id' => 3018284, 'status' => 'pending']], 200)]);
    $refund = aPendingRefundWithTransaction();

    ProcessRefundJob::dispatchSync($refund->id);

    expect($refund->fresh()->paystack_refund_id)->toBe('3018284');
    expect($refund->fresh()->statusHistories()->latest('id')->first()->source)->toBe(RefundStatusSourceEnum::initiate->value);
});

// A pending refund that already carries Paystack's id -- the earlier attempt's POST answered, but
// recording its outcome failed, so the job is retrying from `pending`.
function refundJobPendingWithPaystackId(): Refund
{
    $refund = aPendingRefundWithTransaction();
    $refund->transaction->update(['amount' => 5000, 'currency' => 'GHS']);
    $refund->update(['amount' => 5000, 'currency' => 'GHS', 'paystack_refund_id' => '3018284']);

    return $refund->fresh();
}

function refundJobFetchedRefund(array $overrides = []): array
{
    return ['status' => true, 'data' => array_merge([
        'id' => 3018284,
        'transaction_reference' => 'refund_job_txn_ref',
        'amount' => 5000,
        'currency' => 'GHS',
        'status' => 'processed',
    ], $overrides)];
}

function refundJobRunAsRetry(int $refundId): void
{
    $job = (new ProcessRefundJob($refundId))->withFakeQueueInteractions();
    $job->job->attempts = 2;

    $job->handle();
}

test('with a stored refund id, the retry looks the refund up instead of sending it again', function (string $paystackStatus, string $expected) {
    $refund = refundJobPendingWithPaystackId();
    Http::fake([
        '*/refund/*' => Http::response(refundJobFetchedRefund(['status' => $paystackStatus]), 200),
        '*/refund' => Http::response(['status' => false, 'message' => 'Transaction has been fully reversed'], 400),
    ]);

    refundJobRunAsRetry($refund->id);

    expect($refund->fresh()->status)->toBe($expected);
    expect($refund->fresh()->statusHistories()->latest('id')->first()->source)->toBe(RefundStatusSourceEnum::retryLookup->value);
    Http::assertSent(fn ($request) => $request->method() === 'GET' && str_ends_with($request->url(), '/refund/3018284'));
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
})->with([
    'processed' => ['processed', RefundStatusEnum::success->value],
    'failed' => ['failed', RefundStatusEnum::failed->value],
]);

test('with a stored refund id, an inconclusive lookup throws for a later retry and leaves the refund untouched', function (Closure $fetchResponse) {
    $refund = refundJobPendingWithPaystackId();
    Http::fake([
        '*/refund/*' => $fetchResponse,
        '*/refund' => Http::response(['status' => false, 'message' => 'Transaction has been fully reversed'], 400),
    ]);

    expect(fn () => refundJobRunAsRetry($refund->id))->toThrow(RuntimeException::class);

    expect($refund->fresh()->status)->toBe(RefundStatusEnum::pending->value);
    expect($refund->fresh()->statusHistories()->count())->toBe(0);
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
})->with([
    'still pending' => fn () => fn () => Http::response(refundJobFetchedRefund(['status' => 'pending']), 200),
    'still processing' => fn () => fn () => Http::response(refundJobFetchedRefund(['status' => 'processing']), 200),
    'needs attention' => fn () => fn () => Http::response(refundJobFetchedRefund(['status' => 'needs-attention']), 200),
    'no status' => fn () => fn () => Http::response(['status' => true, 'data' => []], 200),
    'not found' => fn () => fn () => Http::response(['status' => false, 'message' => 'Refund not found'], 404),
    'Paystack error' => fn () => fn () => Http::response(['status' => false, 'message' => 'Internal server error'], 500),
    'Paystack unreachable' => fn () => fn () => throw new ConnectionException('timed out'),
    // Security review rule carried over from the payout lookup: only this refund's own record counts.
    'another refund id' => fn () => fn () => Http::response(refundJobFetchedRefund(['id' => 999]), 200),
    'another transaction' => fn () => fn () => Http::response(refundJobFetchedRefund(['transaction_reference' => 'other_ref']), 200),
    'no transaction reference' => fn () => fn () => Http::response(refundJobFetchedRefund(['transaction_reference' => null]), 200),
    'another amount' => fn () => fn () => Http::response(refundJobFetchedRefund(['amount' => 4000]), 200),
    'another currency' => fn () => fn () => Http::response(refundJobFetchedRefund(['currency' => 'NGN']), 200),
    'a mismatched failure' => fn () => fn () => Http::response(refundJobFetchedRefund(['status' => 'failed', 'amount' => 1]), 200),
]);

test('security review: a job that finds a stored refund id never sends a second refund request', function () {
    $refund = refundJobPendingWithPaystackId();
    Http::fake([
        '*/refund/*' => Http::response(refundJobFetchedRefund(['status' => 'processed']), 200),
        '*/refund' => Http::response(['status' => true, 'data' => ['id' => 1, 'status' => 'pending']], 200),
    ]);

    ProcessRefundJob::dispatchSync($refund->id);

    expect($refund->fresh()->status)->toBe(RefundStatusEnum::success->value);
    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
});
