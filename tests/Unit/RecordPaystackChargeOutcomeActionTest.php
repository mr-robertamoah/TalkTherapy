<?php

use App\Actions\Transaction\RecordPaystackChargeOutcomeAction;
use App\Enums\TransactionStatusEnum;
use App\Enums\TransactionStatusSourceEnum;
use App\Exceptions\TransactionException;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Log;

// SCRUM-378: the one Paystack charge/verify outcome mapping shared by ResolveTransactionWithPaystackAction,
// ChargeOrganizationForModelAction and ProcessOrganizationInvoiceSettlementJob. Each caller's own test
// file still covers its end-to-end behaviour; this pins the mapping itself.

function chargeOutcomePendingTransaction(): Transaction
{
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);

    return Transaction::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'amount' => 5000,
        'currency' => 'GHS',
        'status' => TransactionStatusEnum::pending->value,
    ]);
}

function chargeOutcomeRecord(Transaction $transaction, array $data, bool $abandonedIsFailure): Transaction
{
    return RecordPaystackChargeOutcomeAction::new()->execute(
        $transaction,
        $data,
        TransactionStatusSourceEnum::verify->value,
        $abandonedIsFailure
    );
}

test('a success at the transaction amount and currency is recorded as success, with the given source and message', function () {
    $transaction = chargeOutcomePendingTransaction();

    $result = chargeOutcomeRecord($transaction, ['status' => 'success', 'amount' => 5000, 'currency' => 'ghs', 'gateway_response' => 'Approved'], false);

    expect($result->status)->toBe(TransactionStatusEnum::success->value);
    $history = $transaction->statusHistories()->latest('id')->first();
    expect($history->source)->toBe(TransactionStatusSourceEnum::verify->value);
    expect($history->message)->toBe('Approved');
});

test('a success whose amount or currency does not match throws and records nothing', function (array $data) {
    $transaction = chargeOutcomePendingTransaction();

    expect(fn () => chargeOutcomeRecord($transaction, array_merge(['status' => 'success'], $data), true))
        ->toThrow(TransactionException::class);

    expect($transaction->fresh()->status)->toBe(TransactionStatusEnum::pending->value);
    expect($transaction->statusHistories()->count())->toBe(0);
})->with([
    'other amount' => [['amount' => 4999, 'currency' => 'GHS']],
    'other currency' => [['amount' => 5000, 'currency' => 'NGN']],
    'no amount' => [['currency' => 'GHS']],
    'no currency' => [['amount' => 5000]],
]);

test('failed is recorded as failed in both modes', function (bool $abandonedIsFailure) {
    $transaction = chargeOutcomePendingTransaction();

    $result = chargeOutcomeRecord($transaction, ['status' => 'failed', 'gateway_response' => 'Declined'], $abandonedIsFailure);

    expect($result->status)->toBe(TransactionStatusEnum::failed->value);
})->with([true, false]);

test('abandoned is a failure only when the caller says so', function (bool $abandonedIsFailure, string $expected) {
    $transaction = chargeOutcomePendingTransaction();

    $result = chargeOutcomeRecord($transaction, ['status' => 'abandoned'], $abandonedIsFailure);

    expect($result->status)->toBe($expected);
})->with([
    'server charge' => [true, TransactionStatusEnum::failed->value],
    'checkout' => [false, TransactionStatusEnum::abandoned->value],
]);

test('a non-terminal or missing status leaves the transaction as it is', function (array $data) {
    $transaction = chargeOutcomePendingTransaction();

    $result = chargeOutcomeRecord($transaction, $data, true);

    expect($result->status)->toBe(TransactionStatusEnum::pending->value);
    expect($transaction->statusHistories()->count())->toBe(0);
})->with([
    'ongoing' => [['status' => 'ongoing']],
    'processing' => [['status' => 'processing']],
    'queued' => [['status' => 'queued']],
    'pending' => [['status' => 'pending']],
    'no status' => [[]],
]);

test('an abandoned server-to-server charge is logged distinctly from a decline, and a checkout one is not', function () {
    Log::spy();

    chargeOutcomeRecord(chargeOutcomePendingTransaction(), ['status' => 'abandoned'], true);
    chargeOutcomeRecord(chargeOutcomePendingTransaction(), ['status' => 'abandoned'], false);
    chargeOutcomeRecord(chargeOutcomePendingTransaction(), ['status' => 'failed'], true);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message) => str_contains($message, 'abandoned by Paystack'))
        ->once();
});
