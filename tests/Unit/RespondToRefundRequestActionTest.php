<?php

use App\Actions\Request\CreateRequestAction;
use App\Actions\Request\EnsureUserCanRespondToRequestAction;
use App\Actions\Request\RespondToRefundRequestAction;
use App\DTOs\CreateRequestDTO;
use App\DTOs\RequestResponseDTO;
use App\Enums\RefundStatusEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Exceptions\BadRequestException;
use App\Exceptions\CannotRespondToRequestException;
use App\Exceptions\TransactionException;
use App\Jobs\ProcessRefundJob;
use App\Models\PaymentAccessGrant;
use App\Models\Refund;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\RefundRequestRejectedNotification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;

// TT-7.7a/SCRUM-249: mirrors RespondToOrganizationCounsellorCompensationRequestAction's own
// lock-then-mutate, idempotent-on-repeat shape.

// TT-7.7d/SCRUM-252: every test in this file that accepts a refund request now also dispatches
// ProcessRefundJob (which would otherwise run synchronously against the real Paystack API in
// this test environment's QUEUE_CONNECTION=sync) -- faked globally here since this file's own
// tests are about RespondToRefundRequestAction's behavior, not the job's.
beforeEach(function () {
    Bus::fake();
});

function aPendingRefundRequest(): array
{
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);
    $transaction = Transaction::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'status' => 'SUCCESS',
        'amount' => 5000,
        'currency' => 'GHS',
    ]);
    $client = User::factory()->create();

    $request = CreateRequestAction::new()->execute(
        CreateRequestDTO::new()->fromArray([
            'from' => $client,
            'to' => null,
            'for' => $transaction,
            'type' => RequestTypeEnum::refund->value,
            'data' => ['reason' => 'The session never happened.'],
        ])
    );

    return [$request, $transaction, $client];
}

test('accepting creates a pending Refund row snapshotting the transaction amount, currency, and reason', function () {
    [$request, $transaction, $client] = aPendingRefundRequest();

    $result = RespondToRefundRequestAction::new()->execute(
        RequestResponseDTO::new()->fromArray(['response' => 'accepted', 'request' => $request])
    );

    expect($result->status)->toBe(RequestStatusEnum::accepted->value);
    expect(Refund::count())->toBe(1);

    $refund = Refund::first();
    expect($refund->transaction_id)->toBe($transaction->id);
    expect($refund->request_id)->toBe($request->id);
    expect($refund->requested_by_id)->toBe($client->id);
    expect($refund->amount)->toBe(5000);
    expect($refund->currency)->toBe('GHS');
    expect($refund->reason)->toBe('The session never happened.');
    expect($refund->status)->toBe(RefundStatusEnum::pending->value);
    expect($refund->reference)->not->toBeNull();
    expect($refund->statusHistories()->count())->toBe(1);
});

// TT-7.7d/SCRUM-252
test('accepting dispatches ProcessRefundJob for the new Refund, after the transaction commits', function () {
    [$request] = aPendingRefundRequest();

    RespondToRefundRequestAction::new()->execute(
        RequestResponseDTO::new()->fromArray(['response' => 'accepted', 'request' => $request])
    );

    Bus::assertDispatched(ProcessRefundJob::class);
});

test('a reject never dispatches ProcessRefundJob', function () {
    [$request] = aPendingRefundRequest();

    RespondToRefundRequestAction::new()->execute(
        RequestResponseDTO::new()->fromArray(['response' => null, 'reason' => 'No refund warranted here.', 'request' => $request])
    );

    Bus::assertNotDispatched(ProcessRefundJob::class);
});

// An idempotent no-op re-response must not dispatch a second job for the same Refund.
test('responding to an already-accepted request a second time does not dispatch a second job', function () {
    [$request] = aPendingRefundRequest();

    RespondToRefundRequestAction::new()->execute(
        RequestResponseDTO::new()->fromArray(['response' => 'accepted', 'request' => $request])
    );
    RespondToRefundRequestAction::new()->execute(
        RequestResponseDTO::new()->fromArray(['response' => 'accepted', 'request' => $request->fresh()])
    );

    Bus::assertDispatchedTimes(ProcessRefundJob::class, 1);
});

// TT-7.7c/SCRUM-251: a reject is this request's own final word (no later "outcome" step is
// coming the way accept has), so the client is notified immediately and told why.
test('rejecting with a reason leaves the request rejected, creates no Refund row, and notifies the client', function () {
    Notification::fake();
    [$request, , $client] = aPendingRefundRequest();

    $result = RespondToRefundRequestAction::new()->execute(
        RequestResponseDTO::new()->fromArray(['response' => null, 'reason' => 'This session was already completed as scheduled.', 'request' => $request])
    );

    expect($result->status)->toBe(RequestStatusEnum::rejected->value);
    expect(Refund::count())->toBe(0);
    // Distinct from the client's own ask-time `data.reason` -- never overwritten.
    expect($result->data['reason'])->toBe('The session never happened.');
    expect($result->data['rejectionReason'])->toBe('This session was already completed as scheduled.');
    Notification::assertSentTo($client, RefundRequestRejectedNotification::class);
});

test('rejecting without a reason throws and leaves the request untouched', function () {
    Notification::fake();
    [$request] = aPendingRefundRequest();

    expect(fn () => RespondToRefundRequestAction::new()->execute(
        RequestResponseDTO::new()->fromArray(['response' => null, 'request' => $request])
    ))->toThrow(BadRequestException::class);

    expect($request->fresh()->status)->toBe(RequestStatusEnum::pending->value);
    Notification::assertNothingSent();
});

test('rejecting with a blank reason throws the same as no reason at all', function () {
    [$request] = aPendingRefundRequest();

    expect(fn () => RespondToRefundRequestAction::new()->execute(
        RequestResponseDTO::new()->fromArray(['response' => null, 'reason' => '   ', 'request' => $request])
    ))->toThrow(BadRequestException::class);

    expect($request->fresh()->status)->toBe(RequestStatusEnum::pending->value);
});

test('responding to an already-rejected request a second time is a no-op, not a second notification', function () {
    Notification::fake();
    [$request] = aPendingRefundRequest();

    RespondToRefundRequestAction::new()->execute(
        RequestResponseDTO::new()->fromArray(['response' => null, 'reason' => 'Already resolved out of band.', 'request' => $request])
    );

    $second = RespondToRefundRequestAction::new()->execute(
        RequestResponseDTO::new()->fromArray(['response' => null, 'request' => $request->fresh()])
    );

    expect($second->status)->toBe(RequestStatusEnum::rejected->value);
    Notification::assertSentTimes(RefundRequestRejectedNotification::class, 1);
});

test('responding to an already-accepted request a second time is a no-op, not a duplicate Refund row', function () {
    [$request] = aPendingRefundRequest();

    RespondToRefundRequestAction::new()->execute(
        RequestResponseDTO::new()->fromArray(['response' => 'accepted', 'request' => $request])
    );

    $second = RespondToRefundRequestAction::new()->execute(
        RequestResponseDTO::new()->fromArray(['response' => 'accepted', 'request' => $request->fresh()])
    );

    expect($second->status)->toBe(RequestStatusEnum::accepted->value);
    expect(Refund::count())->toBe(1);
});

// The DB transaction wrapping the whole method must roll back the request's own status flip too
// -- otherwise a request could end up ACCEPTED with no Refund row to show for it.
test('accepting a request whose transaction has since become ineligible rolls back the status change too', function () {
    [$request, $transaction] = aPendingRefundRequest();
    Refund::factory()->create(['transaction_id' => $transaction->id, 'status' => RefundStatusEnum::success->value]);

    expect(fn () => RespondToRefundRequestAction::new()->execute(
        RequestResponseDTO::new()->fromArray(['response' => 'accepted', 'request' => $request])
    ))->toThrow(TransactionException::class);

    expect($request->fresh()->status)->toBe(RequestStatusEnum::pending->value);
    // Only the one seeded above -- the accept attempt created no second row.
    expect(Refund::count())->toBe(1);
});

// Security-engineer finding: two DIFFERENT pending refund Requests for the SAME transaction
// (nothing in this ticket prevents that at creation time -- TT-7.7b's one-active-request-per-
// transaction guard is what will) must not both succeed in creating a Refund row. A genuinely
// concurrent race is closed by RespondToRefundRequestAction's own Transaction-row lockForUpdate()
// (two concurrent transactions serialize on that lock, so the second always re-checks eligibility
// AFTER the first has committed); this sequential test proves the resulting invariant -- a second
// request for an already-refunded transaction cannot also be accepted.
test('accepting a second refund request for a transaction that has already been refunded throws', function () {
    [$firstRequest, $transaction, $client] = aPendingRefundRequest();

    RespondToRefundRequestAction::new()->execute(
        RequestResponseDTO::new()->fromArray(['response' => 'accepted', 'request' => $firstRequest])
    );

    // Created AFTER the first accept -- bypassing any ask-time guard (CreateRequestAction has no
    // eligibility check of its own; that's TT-7.7b's job), simulating the data anomaly a leftover
    // or late-arriving second request would represent.
    $secondRequest = CreateRequestAction::new()->execute(
        CreateRequestDTO::new()->fromArray([
            'from' => $client,
            'to' => null,
            'for' => $transaction,
            'type' => RequestTypeEnum::refund->value,
            'data' => ['reason' => 'Also asking, just in case.'],
        ])
    );

    expect(fn () => RespondToRefundRequestAction::new()->execute(
        RequestResponseDTO::new()->fromArray(['response' => 'accepted', 'request' => $secondRequest])
    ))->toThrow(TransactionException::class);

    expect(Refund::count())->toBe(1);
    expect($secondRequest->fresh()->status)->toBe(RequestStatusEnum::pending->value);
});

// Security-engineer finding: a refund Request's `to` is deliberately null (any admin may respond)
// -- a non-admin caller must still get the intended authorization exception, not an uncaught
// "call to a member function on null" error.
test('a non-admin cannot respond to a refund request', function () {
    [$request] = aPendingRefundRequest();
    $nonAdmin = User::factory()->create();

    expect(fn () => EnsureUserCanRespondToRequestAction::new()->execute(
        RequestResponseDTO::new()->fromArray(['user' => $nonAdmin, 'response' => 'accepted', 'request' => $request])
    ))->toThrow(CannotRespondToRequestException::class);
});

// Security-engineer finding (TT-7.7d/SCRUM-252): seeding the grant for a brand-new, unrelated
// Therapy/User (as this test originally did) would pass identically even if this isolation
// guarantee were entirely absent. Tying the grant to the SAME transaction/therapy/client the
// refund request under test actually belongs to makes this a meaningful regression test.
test('never reads or writes payment_access_grants', function () {
    [$request, $transaction, $client] = aPendingRefundRequest();
    $therapy = $transaction->for;
    $existingGrant = PaymentAccessGrant::create([
        'user_id' => $client->id,
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'transaction_id' => $transaction->id,
        'granted_at' => now(),
    ]);

    RespondToRefundRequestAction::new()->execute(
        RequestResponseDTO::new()->fromArray(['response' => 'accepted', 'request' => $request])
    );

    $this->assertDatabaseCount('payment_access_grants', 1);
    expect($existingGrant->fresh()->granted_at->equalTo($existingGrant->granted_at))->toBeTrue();
});
