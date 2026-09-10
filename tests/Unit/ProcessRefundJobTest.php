<?php

use App\Enums\RefundStatusEnum;
use App\Jobs\ProcessRefundJob;
use App\Models\PaymentAccessGrant;
use App\Models\Refund;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Client\RequestException;
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
