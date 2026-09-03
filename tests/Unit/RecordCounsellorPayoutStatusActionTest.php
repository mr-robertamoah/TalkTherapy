<?php

use App\Actions\Payout\RecordCounsellorPayoutStatusAction;
use App\Enums\CounsellorEarningStatusEnum;
use App\Enums\CounsellorPayoutStatusEnum;
use App\Enums\CounsellorPayoutStatusSourceEnum;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\CounsellorEarning;
use App\Models\CounsellorPayout;
use App\Models\PaymentAccessGrant;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\PayoutFailedNotification;
use Illuminate\Support\Facades\Notification;

// TT-7.6c/SCRUM-227: the single choke point a payout's status ever actually changes through --
// mirrors RecordTransactionStatusAction's role/guarantees for the payout-execution lifecycle.

function aClaimedCounsellorEarning(Counsellor $counsellor, CounsellorPayout $payout, int $netAmount = 10000): CounsellorEarning
{
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);
    $transaction = Transaction::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id]);

    return CounsellorEarning::factory()->create([
        'transaction_id' => $transaction->id,
        'counsellor_id' => $counsellor->id,
        'counsellor_payout_id' => $payout->id,
        'net_amount' => $netAmount,
        'currency' => $payout->currency,
        'status' => CounsellorEarningStatusEnum::processing->value,
    ]);
}

test('recording success marks the payout succeeded and its claimed earnings paid_out', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $payout = CounsellorPayout::factory()->create(['counsellor_id' => $counsellor->id]);
    $earning = aClaimedCounsellorEarning($counsellor, $payout);

    RecordCounsellorPayoutStatusAction::new()->execute(
        $payout,
        CounsellorPayoutStatusEnum::succeeded->value,
        CounsellorPayoutStatusSourceEnum::webhook->value
    );

    expect($payout->fresh()->status)->toBe(CounsellorPayoutStatusEnum::succeeded->value);
    expect($earning->fresh()->status)->toBe(CounsellorEarningStatusEnum::paidOut->value);
});

test('recording failure returns the claimed earnings to pending and notifies counsellor and admins', function () {
    Notification::fake();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $admin = User::factory()->has(Administrator::factory())->create();
    $payout = CounsellorPayout::factory()->create(['counsellor_id' => $counsellor->id]);
    $earning = aClaimedCounsellorEarning($counsellor, $payout);

    RecordCounsellorPayoutStatusAction::new()->execute(
        $payout,
        CounsellorPayoutStatusEnum::failed->value,
        CounsellorPayoutStatusSourceEnum::webhook->value,
        'Invalid account details'
    );

    expect($payout->fresh()->status)->toBe(CounsellorPayoutStatusEnum::failed->value);
    expect($earning->fresh()->status)->toBe(CounsellorEarningStatusEnum::pending->value);
    Notification::assertSentTo($counsellor, PayoutFailedNotification::class);
    Notification::assertSentTo($admin, PayoutFailedNotification::class);
});

test('a terminal payout status is never regressed by a later, differently-statused event', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $payout = CounsellorPayout::factory()->create(['counsellor_id' => $counsellor->id, 'status' => CounsellorPayoutStatusEnum::succeeded->value]);
    $earning = aClaimedCounsellorEarning($counsellor, $payout);
    $earning->update(['status' => CounsellorEarningStatusEnum::paidOut->value]);

    RecordCounsellorPayoutStatusAction::new()->execute(
        $payout,
        CounsellorPayoutStatusEnum::failed->value,
        CounsellorPayoutStatusSourceEnum::webhook->value,
        'a stale transfer.reversed event'
    );

    // A reversal arriving after an already-recorded success does not retroactively re-fail the
    // payout or claw back the already-paid_out earning.
    expect($payout->fresh()->status)->toBe(CounsellorPayoutStatusEnum::succeeded->value);
    expect($earning->fresh()->status)->toBe(CounsellorEarningStatusEnum::paidOut->value);
});

test('recording the same status twice is idempotent -- no duplicate history, no duplicate notification', function () {
    Notification::fake();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $payout = CounsellorPayout::factory()->create(['counsellor_id' => $counsellor->id]);
    aClaimedCounsellorEarning($counsellor, $payout);

    RecordCounsellorPayoutStatusAction::new()->execute($payout, CounsellorPayoutStatusEnum::failed->value, CounsellorPayoutStatusSourceEnum::webhook->value);
    RecordCounsellorPayoutStatusAction::new()->execute($payout->fresh(), CounsellorPayoutStatusEnum::failed->value, CounsellorPayoutStatusSourceEnum::webhook->value);

    expect($payout->fresh()->statusHistories()->count())->toBe(1);
    Notification::assertSentToTimes($counsellor, PayoutFailedNotification::class, 1);
});

test('recording a payout status never touches an existing payment_access_grants row', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $client = User::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id]);
    $grant = PaymentAccessGrant::create([
        'user_id' => $client->id,
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'granted_at' => now(),
    ]);
    $payout = CounsellorPayout::factory()->create(['counsellor_id' => $counsellor->id]);
    aClaimedCounsellorEarning($counsellor, $payout);

    RecordCounsellorPayoutStatusAction::new()->execute($payout, CounsellorPayoutStatusEnum::succeeded->value, CounsellorPayoutStatusSourceEnum::webhook->value);

    $this->assertDatabaseCount('payment_access_grants', 1);
    expect($grant->fresh()->granted_at->equalTo($grant->granted_at))->toBeTrue();
});
