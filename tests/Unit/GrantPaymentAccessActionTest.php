<?php

use App\Actions\Transaction\GrantPaymentAccessAction;
use App\DTOs\GrantPaymentAccessDTO;
use App\Enums\TransactionStatusEnum;
use App\Models\PaymentAccessGrant;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;

// SCRUM-218/TT-7.5a: first-access payment grant persistence.

test('granting access creates a row recording the user, payable, and transaction', function () {
    $user = User::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);
    $transaction = Transaction::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id, 'status' => TransactionStatusEnum::success->value]);

    $grant = GrantPaymentAccessAction::new()->execute(GrantPaymentAccessDTO::new()->fromArray([
        'user' => $user,
        'for' => $therapy,
        'transaction' => $transaction,
    ]));

    expect($grant->user_id)->toBe($user->id);
    expect($grant->for_type)->toBe(Therapy::class);
    expect($grant->for_id)->toBe($therapy->id);
    expect($grant->transaction_id)->toBe($transaction->id);
    expect($grant->granted_at)->not->toBeNull();

    $this->assertDatabaseCount('payment_access_grants', 1);
});

test('granting access without a transaction leaves transaction_id null', function () {
    $user = User::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);

    $grant = GrantPaymentAccessAction::new()->execute(GrantPaymentAccessDTO::new()->fromArray([
        'user' => $user,
        'for' => $therapy,
    ]));

    expect($grant->transaction_id)->toBeNull();
});

test('granting access for the same (user, payable) pair twice is idempotent', function () {
    $user = User::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);

    $first = GrantPaymentAccessAction::new()->execute(GrantPaymentAccessDTO::new()->fromArray([
        'user' => $user,
        'for' => $therapy,
    ]));

    $second = GrantPaymentAccessAction::new()->execute(GrantPaymentAccessDTO::new()->fromArray([
        'user' => $user,
        'for' => $therapy,
    ]));

    expect($second->id)->toBe($first->id);
    expect($second->granted_at->equalTo($first->granted_at))->toBeTrue();
    $this->assertDatabaseCount('payment_access_grants', 1);
});

test('granting access works for a Session payable (PER_SESSION case), independent of a Therapy grant', function () {
    $user = User::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);

    GrantPaymentAccessAction::new()->execute(GrantPaymentAccessDTO::new()->fromArray([
        'user' => $user,
        'for' => $therapy,
    ]));

    $sessionGrant = GrantPaymentAccessAction::new()->execute(GrantPaymentAccessDTO::new()->fromArray([
        'user' => $user,
        'for' => $session,
    ]));

    expect($sessionGrant->for_type)->toBe(Session::class);
    expect($sessionGrant->for_id)->toBe($session->id);
    $this->assertDatabaseCount('payment_access_grants', 2);
});

test('a grant is immune to the underlying transaction later changing status', function () {
    $user = User::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);
    $transaction = Transaction::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id, 'status' => TransactionStatusEnum::success->value]);

    GrantPaymentAccessAction::new()->execute(GrantPaymentAccessDTO::new()->fromArray([
        'user' => $user,
        'for' => $therapy,
        'transaction' => $transaction,
    ]));

    $transaction->update(['status' => TransactionStatusEnum::failed->value]);

    $grant = PaymentAccessGrant::where('user_id', $user->id)
        ->where('for_type', Therapy::class)
        ->where('for_id', $therapy->id)
        ->first();

    expect($grant)->not->toBeNull();
    expect($grant->granted_at)->not->toBeNull();
});

test('two different users each get an independent grant for the same payable', function () {
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $grantA = GrantPaymentAccessAction::new()->execute(GrantPaymentAccessDTO::new()->fromArray([
        'user' => $userA,
        'for' => $therapy,
    ]));

    $grantB = GrantPaymentAccessAction::new()->execute(GrantPaymentAccessDTO::new()->fromArray([
        'user' => $userB,
        'for' => $therapy,
    ]));

    expect($grantA->id)->not->toBe($grantB->id);
    $this->assertDatabaseCount('payment_access_grants', 2);
});

test('granting access with a non-successful transaction is refused', function () {
    $user = User::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);
    $transaction = Transaction::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id, 'status' => TransactionStatusEnum::pending->value]);

    GrantPaymentAccessAction::new()->execute(GrantPaymentAccessDTO::new()->fromArray([
        'user' => $user,
        'for' => $therapy,
        'transaction' => $transaction,
    ]));
})->throws(InvalidArgumentException::class);
