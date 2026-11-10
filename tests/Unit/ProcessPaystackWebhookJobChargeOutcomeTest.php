<?php

use App\Actions\Transaction\RecordPaystackChargeOutcomeAction;
use App\Enums\TransactionStatusEnum;
use App\Enums\TransactionStatusSourceEnum;
use App\Exceptions\TransactionException;
use App\Jobs\ProcessPaystackWebhookJob;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Log;

// SCRUM-379: the webhook's charge branch records through RecordPaystackChargeOutcomeAction::recordOutcome()
// (the amount/currency check + record shared with the verify/charge callers). Its status still comes from
// the EVENT NAME, never data.status -- these pin that nothing about the webhook's outcome changed.
// PaystackWebhookTest covers the same at the HTTP boundary.

function webhookChargeOutcomePendingTransaction(string $reference): Transaction
{
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);

    return Transaction::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'reference' => $reference,
        'amount' => 5000,
        'currency' => 'GHS',
        'status' => TransactionStatusEnum::pending->value,
    ]);
}

function webhookChargeOutcomeRun(string $event, array $data): void
{
    (new ProcessPaystackWebhookJob(['event' => $event, 'data' => $data]))->handle();
}

test('charge.success records success from the event name, with the webhook source and gateway_response', function () {
    $transaction = webhookChargeOutcomePendingTransaction('wh_outcome_1');

    // data.status deliberately says something the verify mapping would NOT treat as success.
    webhookChargeOutcomeRun('charge.success', [
        'reference' => 'wh_outcome_1',
        'status' => 'abandoned',
        'amount' => 5000,
        'currency' => 'GHS',
        'gateway_response' => 'Approved',
    ]);

    expect($transaction->fresh()->status)->toBe(TransactionStatusEnum::success->value);
    $history = $transaction->statusHistories()->sole();
    expect($history->source)->toBe(TransactionStatusSourceEnum::webhook->value);
    expect($history->message)->toBe('Approved');
});

test('charge.failed records failed from the event name, without an amount check', function () {
    $transaction = webhookChargeOutcomePendingTransaction('wh_outcome_2');

    webhookChargeOutcomeRun('charge.failed', [
        'reference' => 'wh_outcome_2',
        'status' => 'success',
        'amount' => 1,
        'currency' => 'NGN',
        'gateway_response' => 'Declined',
    ]);

    expect($transaction->fresh()->status)->toBe(TransactionStatusEnum::failed->value);
    expect($transaction->statusHistories()->sole()->message)->toBe('Declined');
});

test('a charge.success whose amount or currency does not match throws, failing the job, and records nothing', function (array $data) {
    $transaction = webhookChargeOutcomePendingTransaction('wh_outcome_3');

    expect(fn () => webhookChargeOutcomeRun('charge.success', array_merge(['reference' => 'wh_outcome_3'], $data)))
        ->toThrow(TransactionException::class);

    expect($transaction->fresh()->status)->toBe(TransactionStatusEnum::pending->value);
    expect($transaction->statusHistories()->count())->toBe(0);
})->with([
    'other amount' => [['amount' => 4999, 'currency' => 'GHS']],
    'other currency' => [['amount' => 5000, 'currency' => 'NGN']],
    'no amount' => [['currency' => 'GHS']],
]);

test('any other charge event leaves the transaction as it is', function () {
    $transaction = webhookChargeOutcomePendingTransaction('wh_outcome_4');

    webhookChargeOutcomeRun('charge.dispute.create', ['reference' => 'wh_outcome_4', 'status' => 'success', 'amount' => 5000, 'currency' => 'GHS']);

    expect($transaction->fresh()->status)->toBe(TransactionStatusEnum::pending->value);
    expect($transaction->statusHistories()->count())->toBe(0);
});

test('the webhook never logs the server-to-server abandoned warning', function () {
    Log::spy();
    webhookChargeOutcomePendingTransaction('wh_outcome_5');

    webhookChargeOutcomeRun('charge.failed', ['reference' => 'wh_outcome_5', 'status' => 'abandoned']);

    Log::shouldNotHaveReceived('warning', [Mockery::on(fn ($message) => str_contains($message, 'abandoned by Paystack')), Mockery::any()]);
});

test('recordOutcome records the status it is given, ignoring data.status', function () {
    $transaction = webhookChargeOutcomePendingTransaction('wh_outcome_6');

    $result = RecordPaystackChargeOutcomeAction::new()->recordOutcome(
        $transaction,
        TransactionStatusEnum::failed->value,
        ['status' => 'success', 'gateway_response' => 'Declined'],
        TransactionStatusSourceEnum::webhook->value
    );

    expect($result->status)->toBe(TransactionStatusEnum::failed->value);
});
