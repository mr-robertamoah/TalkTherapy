<?php

use App\Actions\Transaction\GenerateCounsellorEarningsAction;
use App\Enums\CounsellorEarningShareBasisEnum;
use App\Enums\CounsellorEarningStatusEnum;
use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TransactionStatusEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Organization;
use App\Models\PaymentAccessGrant;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;

// TT-7.6b/SCRUM-226: one CounsellorEarning row per counsellor entitled to a share of a
// successful, personally-financed Transaction. Confirmed with product-owner/architect that this
// hard constraint must hold: never reads from or writes to payment_access_grants (TT-7.5a).

function aPersonalTransaction(array $overrides = []): Transaction
{
    return Transaction::factory()->create(array_merge([
        'status' => TransactionStatusEnum::success->value,
        'amount' => 10000,
        'currency' => 'GHS',
    ], $overrides));
}

test('a successful individual therapy transaction generates one 100% earning for its counsellor', function () {
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => $counsellor->id,
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
    ]);
    $transaction = aPersonalTransaction(['for_type' => Therapy::class, 'for_id' => $therapy->id]);

    GenerateCounsellorEarningsAction::new()->execute($transaction);

    $this->assertDatabaseCount('counsellor_earnings', 1);
    $earning = $transaction->earnings()->first();

    expect($earning->counsellor_id)->toBe($counsellor->id);
    expect($earning->gross_amount)->toBe(10000);
    expect($earning->currency)->toBe('GHS');
    expect($earning->share_basis)->toBeNull();
    expect($earning->share_percentage)->toBeNull();
    expect($earning->status)->toBe(CounsellorEarningStatusEnum::pending->value);
});

test('the fee percentage is applied and net_amount is gross minus fee', function () {
    config(['settings.platform_fee_percentage' => 10]);

    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => $counsellor->id,
    ]);
    $transaction = aPersonalTransaction(['for_type' => Therapy::class, 'for_id' => $therapy->id, 'amount' => 10000]);

    GenerateCounsellorEarningsAction::new()->execute($transaction);

    $earning = $transaction->earnings()->first();

    expect($earning->fee_amount)->toBe(1000);
    expect($earning->net_amount)->toBe(9000);
});

test('a fractional fee percentage is computed via integer basis points, not float multiplication', function () {
    config(['settings.platform_fee_percentage' => 12.5]);

    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => $counsellor->id,
    ]);
    $transaction = aPersonalTransaction(['for_type' => Therapy::class, 'for_id' => $therapy->id, 'amount' => 10000]);

    GenerateCounsellorEarningsAction::new()->execute($transaction);

    $earning = $transaction->earnings()->first();

    expect($earning->fee_amount)->toBe(1250);
    expect($earning->net_amount)->toBe(8750);
});

test('a PER_SESSION transaction resolves through the session to its parent therapy counsellor', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => $counsellor->id,
    ]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);
    $transaction = aPersonalTransaction(['for_type' => Session::class, 'for_id' => $session->id]);

    GenerateCounsellorEarningsAction::new()->execute($transaction);

    $this->assertDatabaseCount('counsellor_earnings', 1);
    expect($transaction->earnings()->first()->counsellor_id)->toBe($counsellor->id);
});

test('an org-financed transaction generates no earnings at all', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => $counsellor->id,
    ]);
    $organization = Organization::factory()->create();
    $transaction = aPersonalTransaction([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'organization_id' => $organization->id,
    ]);

    GenerateCounsellorEarningsAction::new()->execute($transaction);

    $this->assertDatabaseCount('counsellor_earnings', 0);
});

test('a therapy with no assigned counsellor generates no earnings, without throwing', function () {
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => null,
    ]);
    $transaction = aPersonalTransaction(['for_type' => Therapy::class, 'for_id' => $therapy->id]);

    GenerateCounsellorEarningsAction::new()->execute($transaction);

    $this->assertDatabaseCount('counsellor_earnings', 0);
});

test('calling execute twice for the same transaction does not create duplicate earnings', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => $counsellor->id,
    ]);
    $transaction = aPersonalTransaction(['for_type' => Therapy::class, 'for_id' => $therapy->id]);

    GenerateCounsellorEarningsAction::new()->execute($transaction);
    GenerateCounsellorEarningsAction::new()->execute($transaction);

    $this->assertDatabaseCount('counsellor_earnings', 1);
});

test('an earning gets an initial status history row recorded alongside it', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => $counsellor->id,
    ]);
    $transaction = aPersonalTransaction(['for_type' => Therapy::class, 'for_id' => $therapy->id]);

    GenerateCounsellorEarningsAction::new()->execute($transaction);

    $earning = $transaction->earnings()->first();

    expect($earning->statusHistories()->count())->toBe(1);
    expect($earning->statusHistories()->first()->status)->toBe(CounsellorEarningStatusEnum::pending->value);
});

test('a group therapy with shareEqually splits the whole amount equally among active counsellors', function () {
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 100, 'currency' => 'GHS', 'shareEqually' => true],
    ]);
    $counsellorA = Counsellor::factory()->create(['user_id' => User::factory()]);
    $counsellorB = Counsellor::factory()->create(['user_id' => User::factory()]);
    $groupTherapy->counsellors()->attach([$counsellorA->id, $counsellorB->id], [
        'state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL',
    ]);
    $transaction = aPersonalTransaction(['for_type' => GroupTherapy::class, 'for_id' => $groupTherapy->id, 'amount' => 10000]);

    GenerateCounsellorEarningsAction::new()->execute($transaction);

    $this->assertDatabaseCount('counsellor_earnings', 2);
    $earnings = $transaction->earnings;

    expect($earnings->sum('gross_amount'))->toBe(10000);
    expect($earnings->pluck('share_basis')->unique()->all())->toBe([CounsellorEarningShareBasisEnum::equal->value]);
    expect($earnings->pluck('share_percentage')->unique()->all())->toBe([null]);
});

test('a group therapy with sharePercentage allocates only that pool to counsellors, split equally', function () {
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 100, 'currency' => 'GHS', 'shareEqually' => false, 'sharePercentage' => 80],
    ]);
    $counsellorA = Counsellor::factory()->create(['user_id' => User::factory()]);
    $counsellorB = Counsellor::factory()->create(['user_id' => User::factory()]);
    $groupTherapy->counsellors()->attach([$counsellorA->id, $counsellorB->id], [
        'state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL',
    ]);
    $transaction = aPersonalTransaction(['for_type' => GroupTherapy::class, 'for_id' => $groupTherapy->id, 'amount' => 10000]);

    GenerateCounsellorEarningsAction::new()->execute($transaction);

    $earnings = $transaction->earnings;

    // Pool = 80% of 10000 = 8000, split equally between 2 counsellors = 4000 each.
    expect($earnings->sum('gross_amount'))->toBe(8000);
    expect($earnings->pluck('share_percentage')->unique()->all())->toBe([80]);
    expect($earnings->pluck('share_basis')->unique()->all())->toBe([CounsellorEarningShareBasisEnum::percentage->value]);
});

test('an uneven equal split assigns the leftover minor-unit remainder to one counsellor, never dropping it', function () {
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 100, 'currency' => 'GHS', 'shareEqually' => true],
    ]);
    $counsellors = Counsellor::factory()->count(3)->create(['user_id' => User::factory()]);
    $groupTherapy->counsellors()->attach($counsellors->pluck('id')->all(), [
        'state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL',
    ]);
    // 10001 / 3 = 3333 remainder 2 -- the remainder must be accounted for, not lost.
    $transaction = aPersonalTransaction(['for_type' => GroupTherapy::class, 'for_id' => $groupTherapy->id, 'amount' => 10001]);

    GenerateCounsellorEarningsAction::new()->execute($transaction);

    expect($transaction->earnings->sum('gross_amount'))->toBe(10001);
});

test('an inactive counsellor on a group therapy is excluded from the split', function () {
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 100, 'currency' => 'GHS', 'shareEqually' => true],
    ]);
    $active = Counsellor::factory()->create(['user_id' => User::factory()]);
    $removed = Counsellor::factory()->create(['user_id' => User::factory()]);
    $groupTherapy->counsellors()->attach($active->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);
    $groupTherapy->counsellors()->attach($removed->id, ['state' => CounsellorGroupTherapyStateEnum::inactive->value, 'role' => 'NORMAL']);
    $transaction = aPersonalTransaction(['for_type' => GroupTherapy::class, 'for_id' => $groupTherapy->id, 'amount' => 10000]);

    GenerateCounsellorEarningsAction::new()->execute($transaction);

    $this->assertDatabaseCount('counsellor_earnings', 1);
    expect($transaction->earnings()->first()->counsellor_id)->toBe($active->id);
});

test('a group therapy with no active counsellors generates no earnings, without throwing', function () {
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
    ]);
    $transaction = aPersonalTransaction(['for_type' => GroupTherapy::class, 'for_id' => $groupTherapy->id]);

    GenerateCounsellorEarningsAction::new()->execute($transaction);

    $this->assertDatabaseCount('counsellor_earnings', 0);
});

test('an out-of-range sharePercentage bypassing normal validation is clamped, not allowed to over/under-allocate', function () {
    // EnsureTherapyDataIsValidAction is the only current writer of payment_data and already
    // bounds sharePercentage to 40-100/70-100 -- this simulates that invariant being bypassed
    // (a future admin tool, a migration, tinker) to prove this money-handling action defends
    // itself rather than relying solely on a different layer (security review finding).
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 100, 'currency' => 'GHS', 'shareEqually' => false, 'sharePercentage' => 150],
    ]);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);
    $transaction = aPersonalTransaction(['for_type' => GroupTherapy::class, 'for_id' => $groupTherapy->id, 'amount' => 10000]);

    GenerateCounsellorEarningsAction::new()->execute($transaction);

    // Clamped to 100%, never allowed to exceed the actual transaction amount.
    expect($transaction->earnings()->first()->gross_amount)->toBe(10000);
});

test('generating counsellor earnings never touches an existing payment_access_grants row', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $client = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
    ]);
    $existingGrant = PaymentAccessGrant::create([
        'user_id' => $client->id,
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'granted_at' => now(),
    ]);
    $transaction = aPersonalTransaction(['for_type' => Therapy::class, 'for_id' => $therapy->id, 'user_id' => $client->id]);

    GenerateCounsellorEarningsAction::new()->execute($transaction);

    $this->assertDatabaseCount('payment_access_grants', 1);
    expect($existingGrant->fresh()->granted_at->equalTo($existingGrant->granted_at))->toBeTrue();
});
