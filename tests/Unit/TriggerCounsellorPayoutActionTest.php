<?php

use App\DTOs\TriggerPayoutDTO;
use App\Enums\CounsellorEarningStatusEnum;
use App\Enums\CounsellorPayoutStatusEnum;
use App\Exceptions\PayoutException;
use App\Jobs\ProcessCounsellorPayoutJob;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\CounsellorEarning;
use App\Models\CounsellorPayoutAccount;
use App\Models\PaymentAccessGrant;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PayoutService;
use Illuminate\Support\Facades\Bus;

// TT-7.6c/SCRUM-227: payout execution -- the highest-risk sub-ticket in TT-7.6, since real money
// leaves the platform as a direct consequence of this succeeding.

// CounsellorEarning::factory()'s default transaction_id nests Transaction::factory(), which has
// no for_type/for_id of its own (TransactionFactory's own convention -- see GenerateCounsellorEarningsActionTest)
// -- a minimal real Transaction (against a throwaway Therapy) satisfies the NOT NULL morph
// columns without this test suite needing to care about that Transaction's own validity.
function aCounsellorEarning(int $counsellorId, int $netAmount, string $currency): CounsellorEarning
{
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);
    $transaction = Transaction::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id]);

    return CounsellorEarning::factory()->create([
        'transaction_id' => $transaction->id,
        'counsellor_id' => $counsellorId,
        'net_amount' => $netAmount,
        'currency' => $currency,
        'status' => CounsellorEarningStatusEnum::pending->value,
    ]);
}

function aCounsellorWithPayoutDestinationAndBalance(int $netAmount = 10000, string $currency = 'GHS'): array
{
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    CounsellorPayoutAccount::factory()->create(['counsellor_id' => $counsellor->id, 'currency' => $currency]);
    aCounsellorEarning($counsellor->id, $netAmount, $currency);

    return [$counsellorUser, $counsellor];
}

beforeEach(function () {
    Bus::fake();
    config(['settings.minimum_payout_amount.GHS' => 5000]);
});

test('a counsellor can trigger their own payout when balance meets the minimum threshold', function () {
    [$counsellorUser, $counsellor] = aCounsellorWithPayoutDestinationAndBalance(10000);

    $payout = PayoutService::new()->triggerPayout(TriggerPayoutDTO::new()->fromArray([
        'user' => $counsellorUser,
    ]));

    expect($payout->counsellor_id)->toBe($counsellor->id);
    expect($payout->initiated_by_id)->toBe($counsellorUser->id);
    expect($payout->amount)->toBe(10000);
    expect($payout->status)->toBe(CounsellorPayoutStatusEnum::pending->value);
    $this->assertDatabaseCount('counsellor_payouts', 1);

    $earning = CounsellorEarning::first();
    expect($earning->status)->toBe(CounsellorEarningStatusEnum::processing->value);
    expect($earning->counsellor_payout_id)->toBe($payout->id);

    Bus::assertDispatched(ProcessCounsellorPayoutJob::class);
});

test('an admin can trigger a payout on a counsellor\'s behalf', function () {
    [$counsellorUser, $counsellor] = aCounsellorWithPayoutDestinationAndBalance(10000);
    $admin = User::factory()->has(Administrator::factory())->create();

    $payout = PayoutService::new()->triggerPayout(TriggerPayoutDTO::new()->fromArray([
        'user' => $admin,
        'counsellorId' => $counsellor->id,
    ]));

    expect($payout->counsellor_id)->toBe($counsellor->id);
    expect($payout->initiated_by_id)->toBe($admin->id);
});

test('admin-triggered payout does NOT bypass the minimum threshold', function () {
    [$counsellorUser, $counsellor] = aCounsellorWithPayoutDestinationAndBalance(1000);
    $admin = User::factory()->has(Administrator::factory())->create();

    PayoutService::new()->triggerPayout(TriggerPayoutDTO::new()->fromArray([
        'user' => $admin,
        'counsellorId' => $counsellor->id,
    ]));
})->throws(PayoutException::class);

test('a counsellor below the minimum threshold cannot trigger their own payout either', function () {
    [$counsellorUser, $counsellor] = aCounsellorWithPayoutDestinationAndBalance(1000);

    PayoutService::new()->triggerPayout(TriggerPayoutDTO::new()->fromArray([
        'user' => $counsellorUser,
    ]));
})->throws(PayoutException::class);

test('a plain user (not a counsellor, not an admin) cannot trigger any payout', function () {
    $plainUser = User::factory()->create();

    PayoutService::new()->triggerPayout(TriggerPayoutDTO::new()->fromArray([
        'user' => $plainUser,
    ]));
})->throws(PayoutException::class);

test('a counsellor without a payout destination cannot trigger a payout', function () {
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    aCounsellorEarning($counsellor->id, 10000, 'GHS');

    PayoutService::new()->triggerPayout(TriggerPayoutDTO::new()->fromArray([
        'user' => $counsellorUser,
    ]));
})->throws(PayoutException::class);

test('only earnings matching the payout destination currency are claimed', function () {
    [$counsellorUser, $counsellor] = aCounsellorWithPayoutDestinationAndBalance(10000, 'GHS');
    aCounsellorEarning($counsellor->id, 50000, 'USD');

    $payout = PayoutService::new()->triggerPayout(TriggerPayoutDTO::new()->fromArray([
        'user' => $counsellorUser,
    ]));

    expect($payout->amount)->toBe(10000);
    expect($payout->currency)->toBe('GHS');
    $usdEarning = CounsellorEarning::where('currency', 'USD')->first();
    expect($usdEarning->status)->toBe(CounsellorEarningStatusEnum::pending->value);
    expect($usdEarning->counsellor_payout_id)->toBeNull();
});

test('a second trigger attempt after the first has already claimed the balance finds nothing left to pay out', function () {
    // Not literal concurrent threads (Pest runs single-threaded), but proves the state-machine
    // side of the idempotency guarantee: once earnings are flipped to `processing` by the first
    // trigger, a second trigger's own `where('status', 'pending')` finds zero rows -- the same
    // mechanism that protects against a genuine admin-vs-counsellor race.
    [$counsellorUser, $counsellor] = aCounsellorWithPayoutDestinationAndBalance(10000);

    PayoutService::new()->triggerPayout(TriggerPayoutDTO::new()->fromArray(['user' => $counsellorUser]));

    PayoutService::new()->triggerPayout(TriggerPayoutDTO::new()->fromArray(['user' => $counsellorUser]));
})->throws(PayoutException::class);

test('triggering a payout never reads from or writes to payment_access_grants', function () {
    [$counsellorUser, $counsellor] = aCounsellorWithPayoutDestinationAndBalance(10000);
    $client = User::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id]);
    $grant = PaymentAccessGrant::create([
        'user_id' => $client->id,
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'granted_at' => now(),
    ]);

    PayoutService::new()->triggerPayout(TriggerPayoutDTO::new()->fromArray(['user' => $counsellorUser]));

    $this->assertDatabaseCount('payment_access_grants', 1);
    expect($grant->fresh()->granted_at->equalTo($grant->granted_at))->toBeTrue();
});
