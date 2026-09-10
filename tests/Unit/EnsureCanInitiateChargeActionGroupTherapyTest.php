<?php

use App\DTOs\TransactionDTO;
use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapyPerPaymentEnum;
use App\Enums\TransactionStatusEnum;
use App\Exceptions\TransactionException;
use App\Models\GroupTherapy;
use App\Models\Session as TherapySession;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Support\Facades\Http;

// SCRUM-257: found during SCRUM-256 (TT-7.4d)'s product-owner scoping pass -- a live,
// already-reachable defect, not hypothetical. `transactions.initiate.group_therapy` already
// exists and `EnsureCanPayForModelAction` already authorizes any GroupTherapy participant to hit
// it; only the frontend currently hides the control. EnsureCanInitiateChargeAction's
// "already paid" check had no user scoping at all, so one member's successful payment
// permanently blocked every OTHER member from ever paying against the same GroupTherapy.

function aPaidGroupTherapy(array $paymentDataOverrides = []): GroupTherapy
{
    return GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => array_merge([
            'per' => TherapyPerPaymentEnum::therapy->value,
            'amount' => 100,
            'currency' => 'GHS',
        ], $paymentDataOverrides),
    ]);
}

function aGroupTherapyMember(GroupTherapy $groupTherapy): User
{
    $member = User::factory()->create();
    $groupTherapy->users()->attach($member->id, ['background_story' => 'test', 'anonymous' => false]);

    return $member;
}

function fakePaystackInitializeResponseFor(string $reference): void
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

test('a group therapy member can pay even after a different member has already successfully paid', function () {
    $groupTherapy = aPaidGroupTherapy();
    $memberA = aGroupTherapyMember($groupTherapy);
    $memberB = aGroupTherapyMember($groupTherapy);

    Transaction::factory()->create([
        'for_type' => GroupTherapy::class,
        'for_id' => $groupTherapy->id,
        'user_id' => $memberA->id,
        'status' => TransactionStatusEnum::success->value,
    ]);

    fakePaystackInitializeResponseFor('ref_member_b');

    $result = TransactionService::new()->initiateCharge(
        TransactionDTO::new()->fromArray([
            'user' => $memberB,
            'for' => $groupTherapy,
            'callbackUrl' => 'https://talktherapy.tech/transactions/callback',
        ])
    );

    expect($result['transaction'])->toBeInstanceOf(Transaction::class);
    expect($result['transaction']->user_id)->toBe($memberB->id);
});

test('the same group therapy member cannot pay twice for the same group therapy', function () {
    $groupTherapy = aPaidGroupTherapy();
    $member = aGroupTherapyMember($groupTherapy);

    Transaction::factory()->create([
        'for_type' => GroupTherapy::class,
        'for_id' => $groupTherapy->id,
        'user_id' => $member->id,
        'status' => TransactionStatusEnum::success->value,
    ]);

    expect(fn () => TransactionService::new()->initiateCharge(
        TransactionDTO::new()->fromArray([
            'user' => $member,
            'for' => $groupTherapy,
        ])
    ))->toThrow(TransactionException::class, 'This has already been paid for.');
});

test('a group therapy member can pay for a PER_SESSION session even after a different member has already paid for it', function () {
    $groupTherapy = aPaidGroupTherapy(['per' => TherapyPerPaymentEnum::session->value]);
    $memberA = aGroupTherapyMember($groupTherapy);
    $memberB = aGroupTherapyMember($groupTherapy);
    $session = TherapySession::factory()->create([
        'for_type' => GroupTherapy::class,
        'for_id' => $groupTherapy->id,
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
    ]);

    Transaction::factory()->create([
        'for_type' => TherapySession::class,
        'for_id' => $session->id,
        'user_id' => $memberA->id,
        'status' => TransactionStatusEnum::success->value,
    ]);

    fakePaystackInitializeResponseFor('ref_session_member_b');

    $result = TransactionService::new()->initiateCharge(
        TransactionDTO::new()->fromArray([
            'user' => $memberB,
            'for' => $session,
            'callbackUrl' => 'https://talktherapy.tech/transactions/callback',
        ])
    );

    expect($result['transaction'])->toBeInstanceOf(Transaction::class);
    expect($result['transaction']->user_id)->toBe($memberB->id);
});

// Regression: an individual (non-group) Session must keep its existing model-scoped behavior --
// exactly one legitimate payer (its own client), so a second attempt by that same client stays
// blocked exactly as before this fix.
test('an individual therapy session cannot be paid for twice', function () {
    $client = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => [
            'per' => TherapyPerPaymentEnum::session->value,
            'amount' => 40,
            'currency' => 'GHS',
        ],
    ]);
    $session = TherapySession::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
    ]);

    Transaction::factory()->create([
        'for_type' => TherapySession::class,
        'for_id' => $session->id,
        'user_id' => $client->id,
        'status' => TransactionStatusEnum::success->value,
    ]);

    expect(fn () => TransactionService::new()->initiateCharge(
        TransactionDTO::new()->fromArray([
            'user' => $client,
            'for' => $session,
        ])
    ))->toThrow(TransactionException::class, 'This has already been paid for.');
});
