<?php

use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapyPerPaymentEnum;
use App\Enums\TherapySessionTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Enums\TransactionStatusEnum;
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
        'status' => TransactionStatusEnum::pending->value,
    ]);

    $response = postSignedPaystackWebhook([
        'event' => 'charge.success',
        'data' => [
            'reference' => 'webhook_ref_1',
            'amount' => 15000,
            'gateway_response' => 'Successful',
        ],
    ], $secret);

    $response->assertOk();
    expect($transaction->fresh()->status)->toBe(TransactionStatusEnum::success->value);
    expect($transaction->statusHistories()->count())->toBe(1);
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
        'status' => TransactionStatusEnum::pending->value,
    ]);

    $payload = [
        'event' => 'charge.success',
        'data' => ['reference' => 'webhook_ref_3', 'gateway_response' => 'Successful'],
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
