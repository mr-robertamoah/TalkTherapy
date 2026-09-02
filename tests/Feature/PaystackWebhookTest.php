<?php

use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapyPerPaymentEnum;
use App\Enums\TherapySessionTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Enums\TransactionStatusEnum;
use App\Models\Counsellor;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;

// SCRUM-110: exercised at the real HTTP boundary (not a unit test with a hand-built DTO)
// specifically because signature verification depends on the exact raw request body bytes --
// the one thing a unit test constructing a DTO by hand can't actually prove.

function postSignedPaystackWebhook(array $payload, string $secret)
{
    $rawBody = json_encode($payload);
    $signature = hash_hmac('sha512', $rawBody, $secret);

    return test()->postJson('/api/paystack/webhook', $payload, [
        'x-paystack-signature' => $signature,
    ]);
}

test('a correctly signed charge.success webhook marks the transaction successful', function () {
    $secret = 'test_secret';
    config(['services.paystack.secret_key' => $secret]);

    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'session_type' => TherapySessionTypeEnum::once->value,
        'status' => TherapyStatusEnum::in_session->value,
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => TherapyPerPaymentEnum::therapy->value, 'amount' => 150, 'currency' => 'GHS'],
    ]);
    $transaction = Transaction::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'reference' => 'webhook_ref_1',
        'amount' => 15000,
        'currency' => 'GHS',
        'status' => TransactionStatusEnum::pending->value,
    ]);

    $response = postSignedPaystackWebhook([
        'event' => 'charge.success',
        'data' => [
            'reference' => 'webhook_ref_1',
            'amount' => 15000,
            'currency' => 'GHS',
            'gateway_response' => 'Successful',
        ],
    ], $secret);

    $response->assertOk();
    expect($transaction->fresh()->status)->toBe(TransactionStatusEnum::success->value);
    expect($transaction->statusHistories()->count())->toBe(1);
});

// TT-7.6b/SCRUM-226 (reviewer finding): unit-testing GenerateCounsellorEarningsAction in
// isolation proves the arithmetic, but not that RecordTransactionStatusAction's real callers
// actually reach it -- this exercises the genuine end-to-end path a live Paystack webhook uses.
test('a correctly signed charge.success webhook also generates a counsellor earning', function () {
    $secret = 'test_secret';
    config(['services.paystack.secret_key' => $secret]);

    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => $counsellor->id,
        'session_type' => TherapySessionTypeEnum::once->value,
        'status' => TherapyStatusEnum::in_session->value,
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => TherapyPerPaymentEnum::therapy->value, 'amount' => 150, 'currency' => 'GHS'],
    ]);
    $transaction = Transaction::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'reference' => 'webhook_ref_earnings_1',
        'amount' => 15000,
        'currency' => 'GHS',
        'status' => TransactionStatusEnum::pending->value,
    ]);

    postSignedPaystackWebhook([
        'event' => 'charge.success',
        'data' => [
            'reference' => 'webhook_ref_earnings_1',
            'amount' => 15000,
            'currency' => 'GHS',
            'gateway_response' => 'Successful',
        ],
    ], $secret)->assertOk();

    expect($transaction->fresh()->status)->toBe(TransactionStatusEnum::success->value);
    $this->assertDatabaseCount('counsellor_earnings', 1);
    expect($transaction->earnings()->first()->counsellor_id)->toBe($counsellor->id);
});

test('a webhook with an invalid signature is rejected and never changes the transaction', function () {
    config(['services.paystack.secret_key' => 'test_secret']);

    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => TherapyPerPaymentEnum::therapy->value, 'amount' => 150, 'currency' => 'GHS'],
    ]);
    $transaction = Transaction::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'reference' => 'webhook_ref_2',
        'status' => TransactionStatusEnum::pending->value,
    ]);

    $response = test()->postJson('/api/paystack/webhook', [
        'event' => 'charge.success',
        'data' => ['reference' => 'webhook_ref_2'],
    ], [
        'x-paystack-signature' => 'not-the-right-signature',
    ]);

    $response->assertStatus(401);
    expect($transaction->fresh()->status)->toBe(TransactionStatusEnum::pending->value);
    expect($transaction->statusHistories()->count())->toBe(0);
});

test('the same webhook event delivered twice does not create a duplicate status history entry', function () {
    $secret = 'test_secret';
    config(['services.paystack.secret_key' => $secret]);

    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => TherapyPerPaymentEnum::therapy->value, 'amount' => 150, 'currency' => 'GHS'],
    ]);
    $transaction = Transaction::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'reference' => 'webhook_ref_3',
        'amount' => 15000,
        'currency' => 'GHS',
        'status' => TransactionStatusEnum::pending->value,
    ]);

    $payload = [
        'event' => 'charge.success',
        'data' => ['reference' => 'webhook_ref_3', 'amount' => 15000, 'currency' => 'GHS', 'gateway_response' => 'Successful'],
    ];

    postSignedPaystackWebhook($payload, $secret)->assertOk();
    postSignedPaystackWebhook($payload, $secret)->assertOk();

    expect($transaction->fresh()->status)->toBe(TransactionStatusEnum::success->value);
    expect($transaction->statusHistories()->count())->toBe(1);
});

test('a later charge.failed webhook cannot regress an already-successful transaction', function () {
    $secret = 'test_secret';
    config(['services.paystack.secret_key' => $secret]);

    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => TherapyPerPaymentEnum::therapy->value, 'amount' => 150, 'currency' => 'GHS'],
    ]);
    $transaction = Transaction::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'reference' => 'webhook_ref_4',
        'status' => TransactionStatusEnum::success->value,
    ]);

    postSignedPaystackWebhook([
        'event' => 'charge.failed',
        'data' => ['reference' => 'webhook_ref_4', 'gateway_response' => 'Declined'],
    ], $secret)->assertOk();

    expect($transaction->fresh()->status)->toBe(TransactionStatusEnum::success->value);
    expect($transaction->statusHistories()->count())->toBe(0);
});

// SCRUM-117: signature verification alone doesn't guarantee a legitimately-signed event's
// reported amount/currency actually match what was charged.
test('a charge.success webhook whose amount does not match the transaction is rejected, not recorded as success', function () {
    $secret = 'test_secret';
    config(['services.paystack.secret_key' => $secret]);

    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => TherapyPerPaymentEnum::therapy->value, 'amount' => 150, 'currency' => 'GHS'],
    ]);
    $transaction = Transaction::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'reference' => 'webhook_ref_5',
        'amount' => 15000,
        'currency' => 'GHS',
        'status' => TransactionStatusEnum::pending->value,
    ]);

    $response = postSignedPaystackWebhook([
        'event' => 'charge.success',
        'data' => [
            'reference' => 'webhook_ref_5',
            'amount' => 5000,
            'currency' => 'GHS',
            'gateway_response' => 'Successful',
        ],
    ], $secret);

    $response->assertStatus(422);
    expect($transaction->fresh()->status)->toBe(TransactionStatusEnum::pending->value);
    expect($transaction->statusHistories()->count())->toBe(0);
});

test('a charge.success webhook whose currency does not match the transaction is rejected, not recorded as success', function () {
    $secret = 'test_secret';
    config(['services.paystack.secret_key' => $secret]);

    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => TherapyPerPaymentEnum::therapy->value, 'amount' => 150, 'currency' => 'GHS'],
    ]);
    $transaction = Transaction::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'reference' => 'webhook_ref_6',
        'amount' => 15000,
        'currency' => 'GHS',
        'status' => TransactionStatusEnum::pending->value,
    ]);

    $response = postSignedPaystackWebhook([
        'event' => 'charge.success',
        'data' => [
            'reference' => 'webhook_ref_6',
            'amount' => 15000,
            'currency' => 'USD',
            'gateway_response' => 'Successful',
        ],
    ], $secret);

    $response->assertStatus(422);
    expect($transaction->fresh()->status)->toBe(TransactionStatusEnum::pending->value);
    expect($transaction->statusHistories()->count())->toBe(0);
});
