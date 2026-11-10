<?php

use App\Actions\Request\CreateRequestAction;
use App\Actions\Transaction\EnsureTransactionIsRefundEligibleAction;
use App\DTOs\CreateRequestDTO;
use App\Enums\RefundStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Exceptions\TransactionException;
use App\Models\Organization;
use App\Models\PaymentAccessGrant;
use App\Models\Refund;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;

// TT-7.7a/SCRUM-249: the single eligibility gate that every later refund sub-ticket re-checks.

function aRefundEligibilityTransaction(array $overrides = []): Transaction
{
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);

    return Transaction::factory()->create(array_merge([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
    ], $overrides));
}

test('a successfully paid transaction with no prior refund is eligible', function () {
    $transaction = aRefundEligibilityTransaction(['status' => 'SUCCESS']);

    expect(fn () => EnsureTransactionIsRefundEligibleAction::new()->execute($transaction))->not->toThrow(Throwable::class);
});

test('a pending transaction is not eligible', function () {
    $transaction = aRefundEligibilityTransaction(['status' => 'PENDING']);

    expect(fn () => EnsureTransactionIsRefundEligibleAction::new()->execute($transaction))->toThrow(TransactionException::class);
});

test('a failed transaction is not eligible', function () {
    $transaction = aRefundEligibilityTransaction(['status' => 'FAILED']);

    expect(fn () => EnsureTransactionIsRefundEligibleAction::new()->execute($transaction))->toThrow(TransactionException::class);
});

test('a transaction with an already-successful refund is not eligible again', function () {
    $transaction = aRefundEligibilityTransaction(['status' => 'SUCCESS']);
    Refund::factory()->create(['transaction_id' => $transaction->id, 'status' => RefundStatusEnum::success->value]);

    expect(fn () => EnsureTransactionIsRefundEligibleAction::new()->execute($transaction))->toThrow(TransactionException::class);
});

test('a transaction with a refund already pending or processing is not eligible', function ($status) {
    $transaction = aRefundEligibilityTransaction(['status' => 'SUCCESS']);
    Refund::factory()->create(['transaction_id' => $transaction->id, 'status' => $status]);

    expect(fn () => EnsureTransactionIsRefundEligibleAction::new()->execute($transaction))->toThrow(TransactionException::class);
})->with([RefundStatusEnum::pending->value, RefundStatusEnum::processing->value]);

// A previously-FAILED refund attempt must not permanently block a fresh one.
test('a transaction with only a failed refund attempt is still eligible', function () {
    $transaction = aRefundEligibilityTransaction(['status' => 'SUCCESS']);
    Refund::factory()->create(['transaction_id' => $transaction->id, 'status' => RefundStatusEnum::failed->value]);

    expect(fn () => EnsureTransactionIsRefundEligibleAction::new()->execute($transaction))->not->toThrow(Throwable::class);
});

test('a transaction with an already-pending refund request is not eligible', function () {
    $transaction = aRefundEligibilityTransaction(['status' => 'SUCCESS']);
    $client = User::factory()->create();

    CreateRequestAction::new()->execute(
        CreateRequestDTO::new()->fromArray([
            'from' => $client,
            'to' => null,
            'for' => $transaction,
            'type' => RequestTypeEnum::refund->value,
            'data' => ['reason' => 'Did not receive the service.'],
        ])
    );

    expect(fn () => EnsureTransactionIsRefundEligibleAction::new()->execute($transaction))->toThrow(TransactionException::class);
});

// Product-owner mandate (documentation/decision-log.md's 2026-09-02 SCRUM-223 entry): org-paid
// transactions are refund-eligible from the start, now that TT-7.3b/TT-7.6 exist -- no
// organization_id check blocks this.
test('an org-financed successful transaction is eligible', function () {
    $organization = Organization::factory()->create();
    $transaction = aRefundEligibilityTransaction(['status' => 'SUCCESS', 'organization_id' => $organization->id]);

    expect(fn () => EnsureTransactionIsRefundEligibleAction::new()->execute($transaction))->not->toThrow(Throwable::class);
});

// Explicit regression test carried from TT-7.7's own product-owner mandate (this ticket's own
// scope text repeats it verbatim) -- mirrors ReconcileOrgFinancedRefundActionTest's own version
// of this exact test.
test('never reads or writes payment_access_grants', function () {
    $transaction = aRefundEligibilityTransaction(['status' => 'SUCCESS']);
    $user = User::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);
    $existingGrant = PaymentAccessGrant::create([
        'user_id' => $user->id,
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'granted_at' => now(),
    ]);

    EnsureTransactionIsRefundEligibleAction::new()->execute($transaction);

    $this->assertDatabaseCount('payment_access_grants', 1);
    expect($existingGrant->fresh()->granted_at->equalTo($existingGrant->granted_at))->toBeTrue();
});
