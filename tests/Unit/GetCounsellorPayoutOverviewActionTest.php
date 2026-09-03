<?php

use App\Actions\Payout\GetCounsellorPayoutOverviewAction;
use App\Enums\CounsellorEarningStatusEnum;
use App\Models\Counsellor;
use App\Models\CounsellorEarning;
use App\Models\CounsellorPayout;
use App\Models\CounsellorPayoutAccount;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;

function aTransactionForPayoutOverviewTest(): Transaction
{
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);

    return Transaction::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id]);
}

test('it reports no payout account and null currency when the counsellor has neither a destination nor earnings', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);

    $overview = GetCounsellorPayoutOverviewAction::new()->execute($counsellor);

    expect($overview['payoutAccount'])->toBeNull();
    expect($overview['availableAmount'])->toBe(0);
    expect($overview['currency'])->toBeNull();
    expect($overview['minimumPayoutAmount'])->toBeNull();
});

test('it sums only pending earnings in the payout destination currency', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    CounsellorPayoutAccount::factory()->create(['counsellor_id' => $counsellor->id, 'currency' => 'GHS']);
    CounsellorEarning::factory()->create([
        'transaction_id' => aTransactionForPayoutOverviewTest()->id,
        'counsellor_id' => $counsellor->id,
        'net_amount' => 5000,
        'currency' => 'GHS',
        'status' => CounsellorEarningStatusEnum::pending->value,
    ]);
    CounsellorEarning::factory()->create([
        'transaction_id' => aTransactionForPayoutOverviewTest()->id,
        'counsellor_id' => $counsellor->id,
        'net_amount' => 7000,
        'currency' => 'GHS',
        'status' => CounsellorEarningStatusEnum::pending->value,
    ]);
    // A different currency's pending earning must not be counted toward this destination's balance.
    CounsellorEarning::factory()->create([
        'transaction_id' => aTransactionForPayoutOverviewTest()->id,
        'counsellor_id' => $counsellor->id,
        'net_amount' => 9000,
        'currency' => 'USD',
        'status' => CounsellorEarningStatusEnum::pending->value,
    ]);
    // A non-pending earning must not be counted either.
    CounsellorEarning::factory()->create([
        'transaction_id' => aTransactionForPayoutOverviewTest()->id,
        'counsellor_id' => $counsellor->id,
        'net_amount' => 3000,
        'currency' => 'GHS',
        'status' => CounsellorEarningStatusEnum::processing->value,
    ]);

    $overview = GetCounsellorPayoutOverviewAction::new()->execute($counsellor);

    expect($overview['availableAmount'])->toBe(12000);
    expect($overview['currency'])->toBe('GHS');
    expect($overview['pendingEarnings'])->toHaveCount(2);
});

test('it still surfaces pending earnings and their currency before any payout destination exists', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    CounsellorEarning::factory()->create([
        'transaction_id' => aTransactionForPayoutOverviewTest()->id,
        'counsellor_id' => $counsellor->id,
        'net_amount' => 4000,
        'currency' => 'GHS',
        'status' => CounsellorEarningStatusEnum::pending->value,
    ]);

    $overview = GetCounsellorPayoutOverviewAction::new()->execute($counsellor);

    expect($overview['payoutAccount'])->toBeNull();
    expect($overview['availableAmount'])->toBe(4000);
    expect($overview['currency'])->toBe('GHS');
});

test('it returns up to the 10 most recent payouts as history', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    CounsellorPayout::factory()->count(12)->create(['counsellor_id' => $counsellor->id]);

    $overview = GetCounsellorPayoutOverviewAction::new()->execute($counsellor);

    expect($overview['payoutHistory'])->toHaveCount(10);
});
