<?php

use App\DTOs\TransactionDTO;
use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapyPerPaymentEnum;
use App\Enums\TherapySessionTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Enums\TransactionStatusEnum;
use App\Exceptions\TransactionException;
use App\Models\Counsellor;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Support\Facades\Http;

function aPaidTherapy(array $paymentDataOverrides = [], array $overrides = []): Therapy
{
    return Therapy::factory()->create(array_merge([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'session_type' => TherapySessionTypeEnum::once->value,
        'status' => TherapyStatusEnum::in_session->value,
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => array_merge([
            'per' => TherapyPerPaymentEnum::therapy->value,
            'amount' => 150,
            'currency' => 'GHS',
        ], $paymentDataOverrides),
    ], $overrides));
}

function fakePaystackInitializeResponse(string $reference = 'a_test_reference'): void
{
    Http::fake([
        '*/transaction/initialize' => Http::response([
            'status' => true,
            'message' => 'Authorization URL created',
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/'.$reference,
                'access_code' => $reference,
                'reference' => $reference,
            ],
        ], 200),
    ]);
}

test('initiating a charge for a PAID therapy creates a pending transaction with the correct amount and currency', function () {
    fakePaystackInitializeResponse('ref_123');

    $therapy = aPaidTherapy();
    $payer = $therapy->addedby; // the therapy's own client -- the only one authorized to pay

    $result = TransactionService::new()->initiateCharge(
        TransactionDTO::new()->fromArray([
            'user' => $payer,
            'for' => $therapy,
            'callbackUrl' => 'https://talktherapy.tech/transactions/callback',
        ])
    );

    expect($result['authorizationUrl'])->toBe('https://checkout.paystack.com/ref_123');

    $transaction = $result['transaction'];
    expect($transaction)->toBeInstanceOf(Transaction::class);
    expect($transaction->for_type)->toBe(Therapy::class);
    expect($transaction->for_id)->toBe($therapy->id);
    expect($transaction->user_id)->toBe($payer->id);
    expect($transaction->reference)->toBe('ref_123');
    expect($transaction->amount)->toBe(15000); // 150 GHS in minor units (pesewas)
    expect($transaction->currency)->toBe('GHS');
    expect($transaction->status)->toBe(TransactionStatusEnum::pending->value);

    expect($transaction->statusHistories()->count())->toBe(1);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.paystack.co/transaction/initialize'
            && $request['amount'] === 15000
            && $request['currency'] === 'GHS';
    });
});

test('initiating a charge for a FREE therapy is rejected', function () {
    $therapy = aPaidTherapy(overrides: ['payment_type' => TherapyPaymentTypeEnum::free->value]);

    expect(fn () => TransactionService::new()->initiateCharge(
        TransactionDTO::new()->fromArray([
            'user' => $therapy->addedby,
            'for' => $therapy,
        ])
    ))->toThrow(TransactionException::class, 'There is nothing to pay for here.');
});

test('initiating a charge for something that has already been successfully paid for is rejected', function () {
    $therapy = aPaidTherapy();
    Transaction::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'status' => TransactionStatusEnum::success->value,
    ]);

    expect(fn () => TransactionService::new()->initiateCharge(
        TransactionDTO::new()->fromArray([
            'user' => $therapy->addedby,
            'for' => $therapy,
        ])
    ))->toThrow(TransactionException::class, 'This has already been paid for.');
});

test('initiating a charge with no signed-in user is rejected', function () {
    $therapy = aPaidTherapy();

    expect(fn () => TransactionService::new()->initiateCharge(
        TransactionDTO::new()->fromArray(['for' => $therapy])
    ))->toThrow(TransactionException::class, 'You must be signed in to make a payment.');
});

test('initiating a charge for a missing item is rejected', function () {
    expect(fn () => TransactionService::new()->initiateCharge(
        TransactionDTO::new()->fromArray(['user' => User::factory()->create(), 'for' => null])
    ))->toThrow(TransactionException::class, 'The item you are trying to pay for was not found.');
});

// SCRUM-110 security review: EnsureCanInitiateChargeAction originally had no ownership check at
// all -- any signed-in user could generate a real Paystack checkout link (and see the price)
// for a therapy/session belonging to someone else.

test('a user with no relationship to the therapy cannot initiate a charge for it', function () {
    $therapy = aPaidTherapy();
    $outsider = User::factory()->create();

    expect(fn () => TransactionService::new()->initiateCharge(
        TransactionDTO::new()->fromArray([
            'user' => $outsider,
            'for' => $therapy,
        ])
    ))->toThrow(TransactionException::class, 'You are not authorized to pay for this.');
});

test('the assigned counsellor cannot initiate a charge for their own therapy', function () {
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = aPaidTherapy(overrides: ['counsellor_id' => $counsellor->id]);

    expect(fn () => TransactionService::new()->initiateCharge(
        TransactionDTO::new()->fromArray([
            'user' => $counsellorUser,
            'for' => $therapy,
        ])
    ))->toThrow(TransactionException::class, 'You are not authorized to pay for this.');
});

test('a counsellor with no relationship to an unassigned therapy is cleanly rejected, not crashed', function () {
    // Therapy::isCounsellor() dereferences $this->counsellor with no null check of its own --
    // this proves EnsureCanPayForModelAction guards against calling it on a Therapy that has no
    // counsellor assigned yet, rather than letting that surface as an uncaught Error.
    $counsellorUser = User::factory()->create();
    Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = aPaidTherapy(); // no counsellor_id set

    expect(fn () => TransactionService::new()->initiateCharge(
        TransactionDTO::new()->fromArray([
            'user' => $counsellorUser,
            'for' => $therapy,
        ])
    ))->toThrow(TransactionException::class, 'You are not authorized to pay for this.');
});

test('a PER_THERAPY therapy cannot be charged per session', function () {
    $therapy = aPaidTherapy(); // per = PER_THERAPY (default)
    $session = Session::factory()->create([
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
    ]);

    expect(fn () => TransactionService::new()->initiateCharge(
        TransactionDTO::new()->fromArray([
            'user' => $therapy->addedby,
            'for' => $session,
        ])
    ))->toThrow(TransactionException::class, 'This therapy is paid for as a whole, not per session.');
});

test('a PER_SESSION therapy cannot be charged as a whole', function () {
    $therapy = aPaidTherapy(['per' => TherapyPerPaymentEnum::session->value]);

    expect(fn () => TransactionService::new()->initiateCharge(
        TransactionDTO::new()->fromArray([
            'user' => $therapy->addedby,
            'for' => $therapy,
        ])
    ))->toThrow(TransactionException::class, 'This therapy is paid for per session, not as a whole.');
});

test('verifying a transaction records success and is idempotent on repeat verification', function () {
    $therapy = aPaidTherapy();
    $payer = $therapy->addedby;
    $transaction = Transaction::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'user_id' => $payer->id,
        'reference' => 'ref_456',
        'status' => TransactionStatusEnum::pending->value,
    ]);

    Http::fake([
        '*/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => ['status' => 'success', 'gateway_response' => 'Successful'],
        ], 200),
    ]);

    $result = TransactionService::new()->verifyTransaction(
        TransactionDTO::new()->fromArray(['user' => $payer, 'reference' => 'ref_456'])
    );

    expect($result->status)->toBe(TransactionStatusEnum::success->value);
    expect($transaction->statusHistories()->count())->toBe(1);

    // Repeat verification (e.g. user refreshes the callback page) must not create a duplicate
    // history entry for the same, already-recorded status.
    TransactionService::new()->verifyTransaction(
        TransactionDTO::new()->fromArray(['user' => $payer, 'reference' => 'ref_456'])
    );
    expect($transaction->statusHistories()->count())->toBe(1);
});

test('a later, differently-statused event cannot regress an already-successful transaction', function () {
    $therapy = aPaidTherapy();
    $payer = $therapy->addedby;
    $transaction = Transaction::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'user_id' => $payer->id,
        'reference' => 'ref_regress',
        'status' => TransactionStatusEnum::success->value,
    ]);

    Http::fake([
        '*/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => ['status' => 'failed', 'gateway_response' => 'Declined'],
        ], 200),
    ]);

    $result = TransactionService::new()->verifyTransaction(
        TransactionDTO::new()->fromArray(['user' => $payer, 'reference' => 'ref_regress'])
    );

    expect($result->status)->toBe(TransactionStatusEnum::success->value);
    expect($transaction->statusHistories()->count())->toBe(0);
});

test('verifying an unknown reference throws instead of crashing', function () {
    expect(fn () => TransactionService::new()->verifyTransaction(
        TransactionDTO::new()->fromArray(['user' => User::factory()->create(), 'reference' => 'does-not-exist'])
    ))->toThrow(TransactionException::class, 'Transaction not found.');
});

test('a user cannot verify a transaction that belongs to someone else', function () {
    $therapy = aPaidTherapy();
    Transaction::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'user_id' => $therapy->addedby->id,
        'reference' => 'ref_not_mine',
    ]);

    $outsider = User::factory()->create();

    expect(fn () => TransactionService::new()->verifyTransaction(
        TransactionDTO::new()->fromArray(['user' => $outsider, 'reference' => 'ref_not_mine'])
    ))->toThrow(TransactionException::class, 'Transaction not found.');
});
