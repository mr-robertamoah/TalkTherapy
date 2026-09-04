<?php

use App\Actions\Transaction\GenerateCounsellorEarningsAction;
use App\Enums\CounsellorEarningShareBasisEnum;
use App\Enums\CounsellorEarningStatusEnum;
use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Enums\OrganizationCounsellorCompensationBasisEnum;
use App\Enums\OrganizationCounsellorCompensationTypeEnum;
use App\Enums\OrganizationCounsellorStatusEnum;
use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TransactionStatusEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\OrganizationCounsellorCompensation;
use App\Models\PaymentAccessGrant;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Log;

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

// TT-7.3b-d/SCRUM-235: fully fixes the live bug -- an org-financed individual-Therapy transaction
// used to be a blanket no-op regardless of setup; it now generates a real earning whenever the
// counsellor has active compensation terms with the financing org, re-deriving the split from the
// SAME shared compensation primitives TT-7.3b-b used at charge time.
function anOrgFinancedCompensation(Counsellor $counsellor, array $overrides = []): array
{
    $organization = Organization::factory()->create();
    $affiliation = OrganizationCounsellor::factory()->create([
        'organization_id' => $organization->id,
        'counsellor_id' => $counsellor->id,
        'status' => OrganizationCounsellorStatusEnum::active->value,
    ]);
    OrganizationCounsellorCompensation::factory()->create(array_merge([
        'organization_counsellor_id' => $affiliation->id,
    ], $overrides));

    return [$organization, $affiliation];
}

test('a FIXED-compensation org-financed transaction generates a real earning matching the invariant', function () {
    config(['settings.platform_fee_percentage' => 10]);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    [$organization] = anOrgFinancedCompensation($counsellor, [
        'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
        'amount' => 5000,
        'currency' => 'GHS',
    ]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => $counsellor->id,
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 100, 'currency' => 'GHS'],
    ]);
    // Share = 5000 fixed. Fee = 10% of the GHS 100 (10000 minor units) listed rate = 1000.
    // Transaction.amount (what TT-7.3b-b actually charged) = 6000.
    $transaction = aPersonalTransaction([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'organization_id' => $organization->id,
        'amount' => 6000,
    ]);

    GenerateCounsellorEarningsAction::new()->execute($transaction);

    $this->assertDatabaseCount('counsellor_earnings', 1);
    $earning = $transaction->earnings()->first();
    expect($earning->counsellor_id)->toBe($counsellor->id);
    expect($earning->net_amount)->toBe(5000);
    expect($earning->fee_amount)->toBe(1000);
    expect($earning->gross_amount)->toBe(6000);
    // The ticket's own explicit regression invariant.
    expect($earning->net_amount + $earning->fee_amount)->toBe($transaction->amount);
});

test('a FREE-compensation org-financed transaction still generates an earning -- net 0, fee is the whole amount', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    [$organization] = anOrgFinancedCompensation($counsellor, [
        'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
    ]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => $counsellor->id,
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 100, 'currency' => 'GHS'],
    ]);
    $transaction = aPersonalTransaction([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'organization_id' => $organization->id,
        'amount' => 1000,
    ]);

    GenerateCounsellorEarningsAction::new()->execute($transaction);

    $earning = $transaction->earnings()->first();
    expect($earning->net_amount)->toBe(0);
    expect($earning->fee_amount)->toBe(1000);
    expect($earning->net_amount + $earning->fee_amount)->toBe($transaction->amount);
});

test('a PERCENTAGE counsellorRate org-financed transaction generates a real earning matching the invariant', function () {
    config(['settings.platform_fee_percentage' => 10]);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    [$organization] = anOrgFinancedCompensation($counsellor, [
        'type' => OrganizationCounsellorCompensationTypeEnum::percentage->value,
        'basis' => OrganizationCounsellorCompensationBasisEnum::counsellorRate->value,
        'percentage' => 70,
    ]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => $counsellor->id,
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 100, 'currency' => 'GHS'],
    ]);
    // Share = 70% of the GHS 100 (10000 minor units) listed rate = 7000. Fee = 10% of 10000 = 1000.
    $transaction = aPersonalTransaction([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'organization_id' => $organization->id,
        'amount' => 8000,
    ]);

    GenerateCounsellorEarningsAction::new()->execute($transaction);

    $earning = $transaction->earnings()->first();
    expect($earning->net_amount)->toBe(7000);
    expect($earning->fee_amount)->toBe(1000);
    expect($earning->net_amount + $earning->fee_amount)->toBe($transaction->amount);
});

test('a PERCENTAGE negotiatedRate org-financed transaction generates a real earning matching the invariant', function () {
    config(['settings.platform_fee_percentage' => 10]);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    [$organization] = anOrgFinancedCompensation($counsellor, [
        'type' => OrganizationCounsellorCompensationTypeEnum::percentage->value,
        'basis' => OrganizationCounsellorCompensationBasisEnum::negotiatedRate->value,
        'percentage' => 50,
        'negotiated_rate_amount' => 30000,
    ]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => $counsellor->id,
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 100, 'currency' => 'GHS'],
    ]);
    // Share = 50% of the NEGOTIATED 30000 = 15000 (never the listed rate). Fee = 10% of the
    // LISTED GHS 100 (10000 minor units) = 1000.
    $transaction = aPersonalTransaction([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'organization_id' => $organization->id,
        'amount' => 16000,
    ]);

    GenerateCounsellorEarningsAction::new()->execute($transaction);

    $earning = $transaction->earnings()->first();
    expect($earning->net_amount)->toBe(15000);
    expect($earning->fee_amount)->toBe(1000);
    expect($earning->net_amount + $earning->fee_amount)->toBe($transaction->amount);
});

// Security-engineer finding: ComputeCounsellorCompensationShareAction throws for a
// COUNSELLOR_RATE-basis compensation with no listed amount resolvable -- this must degrade to a
// safe no-op + warning log, not propagate and roll back RecordTransactionStatusAction's whole
// DB::transaction() (which would permanently strand an already-successfully-charged transaction
// as pending, since a webhook replay would just hit the identical exception again).
test('a COUNSELLOR_RATE org-financed transaction with no resolvable listed amount generates no earnings, without throwing', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    [$organization] = anOrgFinancedCompensation($counsellor, [
        'type' => OrganizationCounsellorCompensationTypeEnum::percentage->value,
        'basis' => OrganizationCounsellorCompensationBasisEnum::counsellorRate->value,
        'percentage' => 70,
    ]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => $counsellor->id,
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => null,
    ]);
    $transaction = aPersonalTransaction([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'organization_id' => $organization->id,
    ]);

    expect(fn () => GenerateCounsellorEarningsAction::new()->execute($transaction))
        ->not->toThrow(InvalidArgumentException::class);

    $this->assertDatabaseCount('counsellor_earnings', 0);
});

// Security-engineer finding: a compensation/settings drift between TT-7.3b-b's charge time and
// this earnings-generation call silently reclassifies the difference as platform fee revenue --
// surfaced as a warning (not corrected, since there's no persisted charge-time split to correct
// against) so it's visible for manual reconciliation rather than silent.
test('a compensation change since the original charge is flagged with a warning log', function () {
    Log::spy();
    config(['settings.platform_fee_percentage' => 10]);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    [$organization] = anOrgFinancedCompensation($counsellor, [
        'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
        'amount' => 5000,
        'currency' => 'GHS',
    ]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => $counsellor->id,
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 100, 'currency' => 'GHS'],
    ]);
    // Transaction.amount reflects what was ACTUALLY charged at charge time: share 5000 + fee 1000
    // = 6000. But the compensation amount has since changed to 7000 (e.g. renegotiated) by the
    // time this generation call runs -- net_amount recomputed now (7000) plus the true fee for
    // today's listed rate (1000) would be 8000, which doesn't match the 6000 actually collected.
    OrganizationCounsellorCompensation::query()->where('organization_counsellor_id', $organization->organizationCounsellors()->first()->id)
        ->update(['amount' => 7000]);
    $transaction = aPersonalTransaction([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'organization_id' => $organization->id,
        'amount' => 6000,
    ]);

    GenerateCounsellorEarningsAction::new()->execute($transaction);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message) => str_contains($message, 'does not match the amount implied by the original charge'))
        ->once();
});

test('an org-financed transaction with no active compensation terms generates no earnings, without throwing', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => $counsellor->id,
    ]);
    // An org affiliation with no compensation row set at all -- the counsellor's coverage exists,
    // but there are no terms to compute a share from.
    $organization = Organization::factory()->create();
    OrganizationCounsellor::factory()->create([
        'organization_id' => $organization->id,
        'counsellor_id' => $counsellor->id,
        'status' => OrganizationCounsellorStatusEnum::active->value,
    ]);
    $transaction = aPersonalTransaction([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'organization_id' => $organization->id,
    ]);

    GenerateCounsellorEarningsAction::new()->execute($transaction);

    $this->assertDatabaseCount('counsellor_earnings', 0);
});

test('an org-financed transaction for a therapy with no assigned counsellor generates no earnings, without throwing', function () {
    $organization = Organization::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => null,
    ]);
    $transaction = aPersonalTransaction([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'organization_id' => $organization->id,
    ]);

    GenerateCounsellorEarningsAction::new()->execute($transaction);

    $this->assertDatabaseCount('counsellor_earnings', 0);
});

// Carried forward from TT-7.3b-b/-c's own scope boundary -- GroupTherapy org billing was never
// built, so this transaction's amount is the client's listed price (charged to their own card,
// organization_id set as pure attribution), not a fee+share figure this formula could split.
test('an org-financed GroupTherapy transaction still generates no earnings at all', function () {
    $organization = Organization::factory()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 100, 'currency' => 'GHS', 'shareEqually' => true],
    ]);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);
    $transaction = aPersonalTransaction([
        'for_type' => GroupTherapy::class,
        'for_id' => $groupTherapy->id,
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
