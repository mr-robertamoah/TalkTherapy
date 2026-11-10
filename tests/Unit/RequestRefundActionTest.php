<?php

use App\Actions\Transaction\RequestRefundAction;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Exceptions\TransactionException;
use App\Models\Administrator;
use App\Models\PaymentAccessGrant;
use App\Models\Request;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\RefundRequestedNotification;
use Illuminate\Support\Facades\Notification;

// TT-7.7b/SCRUM-250: the client-facing "ask" -- creates a pending refund Request via the shared
// CreateRequestAction primitive, reusing TT-7.7a's own eligibility gate.

function aSuccessfulPersonalTransaction(array $overrides = []): array
{
    $client = User::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id]);
    $transaction = Transaction::factory()->create(array_merge([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'user_id' => $client->id,
        'status' => 'SUCCESS',
    ], $overrides));

    return [$client, $transaction];
}

beforeEach(function () {
    Notification::fake();
});

test('the transaction owner can request a refund with a reason', function () {
    [$client, $transaction] = aSuccessfulPersonalTransaction();

    $request = RequestRefundAction::new()->execute($client, $transaction, 'The counsellor never joined the session.');

    expect($request->type)->toBe(RequestTypeEnum::refund->value);
    expect($request->status)->toBe(RequestStatusEnum::pending->value);
    expect($request->from_id)->toBe($client->id);
    expect($request->from_type)->toBe(User::class);
    expect($request->to_id)->toBeNull();
    expect($request->for_id)->toBe($transaction->id);
    expect($request->for_type)->toBe(Transaction::class);
    expect($request->data['reason'])->toBe('The counsellor never joined the session.');
});

test('someone other than the transaction owner cannot request a refund for it', function () {
    [, $transaction] = aSuccessfulPersonalTransaction();
    $someoneElse = User::factory()->create();

    expect(fn () => RequestRefundAction::new()->execute($someoneElse, $transaction, 'Not mine, but trying anyway.'))
        ->toThrow(TransactionException::class);

    expect(Request::count())->toBe(0);
});

test('a blank or missing reason is rejected', function ($reason) {
    [$client, $transaction] = aSuccessfulPersonalTransaction();

    expect(fn () => RequestRefundAction::new()->execute($client, $transaction, $reason))
        ->toThrow(TransactionException::class);

    expect(Request::count())->toBe(0);
})->with(['', '   ', null]);

test('an ineligible transaction (not SUCCESS) cannot be requested for refund', function () {
    [$client, $transaction] = aSuccessfulPersonalTransaction(['status' => 'PENDING']);

    expect(fn () => RequestRefundAction::new()->execute($client, $transaction, 'Please refund me.'))
        ->toThrow(TransactionException::class);

    expect(Request::count())->toBe(0);
});

test('notifies 2 random admins', function () {
    [$client, $transaction] = aSuccessfulPersonalTransaction();
    User::factory()->count(3)->has(Administrator::factory())->create();

    RequestRefundAction::new()->execute($client, $transaction, 'Please refund me, this was a mistake.');

    Notification::assertSentTimes(RefundRequestedNotification::class, 2);
});

test('no admins on the platform is a graceful no-op, not a crash', function () {
    [$client, $transaction] = aSuccessfulPersonalTransaction();

    expect(fn () => RequestRefundAction::new()->execute($client, $transaction, 'Please refund me, this was a mistake.'))
        ->not->toThrow(Throwable::class);

    Notification::assertNothingSent();
});

test('never reads or writes payment_access_grants', function () {
    [$client, $transaction] = aSuccessfulPersonalTransaction();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);
    $existingGrant = PaymentAccessGrant::create([
        'user_id' => $client->id,
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'granted_at' => now(),
    ]);

    RequestRefundAction::new()->execute($client, $transaction, 'Please refund me, this was a mistake.');

    $this->assertDatabaseCount('payment_access_grants', 1);
    expect($existingGrant->fresh()->granted_at->equalTo($existingGrant->granted_at))->toBeTrue();
});
