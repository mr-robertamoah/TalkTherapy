<?php

use App\Enums\CounsellorEarningStatusEnum;
use App\Enums\CounsellorPayoutStatusEnum;
use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapyPerPaymentEnum;
use App\Enums\TherapySessionTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Enums\TransactionStatusEnum;
use App\Models\Counsellor;
use App\Models\CounsellorEarning;
use App\Models\CounsellorPayout;
use App\Models\Organization;
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

// TT-7.3b-a/SCRUM-231: proves RecordTransactionStatusAction's real webhook caller actually reaches
// CaptureOrganizationPaymentInstrumentAction too -- not just the unit-tested action in isolation
// (mirrors the earnings test above's own reasoning).
test('a correctly signed charge.success webhook for an org-registration transaction captures a payment instrument', function () {
    $secret = 'test_secret';
    config(['services.paystack.secret_key' => $secret]);

    $organization = Organization::factory()->create();
    $transaction = Transaction::factory()->create([
        'for_type' => Organization::class,
        'for_id' => $organization->id,
        'reference' => 'webhook_ref_org_instrument_1',
        'amount' => 100,
        'currency' => 'GHS',
        'status' => TransactionStatusEnum::pending->value,
    ]);

    postSignedPaystackWebhook([
        'event' => 'charge.success',
        'data' => [
            'reference' => 'webhook_ref_org_instrument_1',
            'amount' => 100,
            'currency' => 'GHS',
            'gateway_response' => 'Successful',
            'authorization' => [
                'authorization_code' => 'AUTH_webhook_1',
                'last4' => '4242',
                'card_type' => 'visa',
                'bank' => 'Test Bank',
                'exp_month' => '12',
                'exp_year' => '2030',
                'reusable' => true,
            ],
        ],
    ], $secret)->assertOk();

    expect($transaction->fresh()->status)->toBe(TransactionStatusEnum::success->value);
    $this->assertDatabaseHas('organization_payment_instruments', [
        'organization_id' => $organization->id,
        'authorization_code' => 'AUTH_webhook_1',
        'pending_credit_amount' => 100,
    ]);
    // Reviewer finding: prove GenerateCounsellorEarningsAction's org guard actually holds through
    // this same real webhook path, not just in isolation -- a verification charge must never
    // generate a bogus counsellor earning.
    $this->assertDatabaseCount('counsellor_earnings', 0);
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

// TT-7.6c/SCRUM-227: extends this same job/webhook route with transfer events (architect
// decision, SCRUM-224 review) -- exercised at the real HTTP boundary for the identical reason
// the charge.* tests above are.

test('a correctly signed transfer.success webhook marks the payout succeeded and its earnings paid_out', function () {
    $secret = 'test_secret';
    config(['services.paystack.secret_key' => $secret]);

    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $payout = CounsellorPayout::factory()->create(['counsellor_id' => $counsellor->id, 'reference' => 'payout_webhook_ref_1']);
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);
    $transaction = Transaction::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id]);
    $earning = CounsellorEarning::factory()->create([
        'transaction_id' => $transaction->id,
        'counsellor_id' => $counsellor->id,
        'counsellor_payout_id' => $payout->id,
        'status' => CounsellorEarningStatusEnum::processing->value,
    ]);

    $response = postSignedPaystackWebhook([
        'event' => 'transfer.success',
        'data' => ['reference' => 'payout_webhook_ref_1'],
    ], $secret);

    $response->assertOk();
    expect($payout->fresh()->status)->toBe(CounsellorPayoutStatusEnum::succeeded->value);
    expect($earning->fresh()->status)->toBe(CounsellorEarningStatusEnum::paidOut->value);
});

test('a correctly signed transfer.failed webhook marks the payout failed and returns earnings to pending', function () {
    $secret = 'test_secret';
    config(['services.paystack.secret_key' => $secret]);

    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $payout = CounsellorPayout::factory()->create(['counsellor_id' => $counsellor->id, 'reference' => 'payout_webhook_ref_2']);
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);
    $transaction = Transaction::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id]);
    $earning = CounsellorEarning::factory()->create([
        'transaction_id' => $transaction->id,
        'counsellor_id' => $counsellor->id,
        'counsellor_payout_id' => $payout->id,
        'status' => CounsellorEarningStatusEnum::processing->value,
    ]);

    $response = postSignedPaystackWebhook([
        'event' => 'transfer.failed',
        'data' => ['reference' => 'payout_webhook_ref_2', 'failure_reason' => 'Invalid account details'],
    ], $secret);

    $response->assertOk();
    expect($payout->fresh()->status)->toBe(CounsellorPayoutStatusEnum::failed->value);
    expect($earning->fresh()->status)->toBe(CounsellorEarningStatusEnum::pending->value);
});
