<?php

use App\DTOs\TransactionDTO;
use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Enums\OrganizationCounsellorStatusEnum;
use App\Enums\OrganizationMemberBillingModeEnum;
use App\Enums\OrganizationMemberStatusEnum;
use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapyPerPaymentEnum;
use App\Enums\TherapySessionTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Exceptions\TransactionException;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\OrganizationMember;
use App\Models\OrganizationMemberBillingConfig;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Support\Facades\Http;

// SCRUM-48 (TT-7.3a): org-as-payer charge initiation. These tests exercise the full
// TransactionService::initiateCharge() pipeline (Paystack faked), not the gate action in
// isolation, so the wiring in TransactionService is proven too, not just the action's own logic.

function fakesPaystackForOrgPayer(string $reference = 'org_payer_ref'): void
{
    Http::fake([
        '*/transaction/initialize' => Http::response([
            'status' => true,
            'data' => ['authorization_url' => 'https://checkout.paystack.com/'.$reference, 'reference' => $reference],
        ], 200),
    ]);
}

function aPaidTherapyForOrgPayer(Counsellor $counsellor, array $overrides = []): Therapy
{
    return Therapy::factory()->create(array_merge([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => $counsellor->id,
        'session_type' => TherapySessionTypeEnum::once->value,
        'status' => TherapyStatusEnum::in_session->value,
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => TherapyPerPaymentEnum::therapy->value, 'amount' => 150, 'currency' => 'GHS'],
    ], $overrides));
}

function aPaidGroupTherapyForOrgPayer(array $overrides = []): GroupTherapy
{
    return GroupTherapy::factory()->create(array_merge([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'session_type' => TherapySessionTypeEnum::once->value,
        'status' => TherapyStatusEnum::in_session->value,
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => TherapyPerPaymentEnum::therapy->value, 'amount' => 150, 'currency' => 'GHS'],
    ], $overrides));
}

// Verified consumer org, an active member (PAY_PER_USE, group therapies included by default) and
// an active counsellor affiliation -- the fully-eligible baseline every rejection test starts
// from and deviates from in exactly one way.
function anEligibleOrgPayerSetup(array $organizationOverrides = [], array $billingConfigOverrides = []): array
{
    $organization = Organization::factory()->create(array_merge([
        'is_provider' => true,
        'is_consumer' => true,
        'verified_at' => now(),
    ], $organizationOverrides));

    $payer = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);

    $member = OrganizationMember::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $payer->id,
        'status' => OrganizationMemberStatusEnum::active->value,
    ]);

    OrganizationMemberBillingConfig::factory()->create(array_merge([
        'organization_member_id' => $member->id,
        'mode' => OrganizationMemberBillingModeEnum::payPerUse->value,
        'include_group_therapies' => true,
    ], $billingConfigOverrides));

    OrganizationCounsellor::factory()->create([
        'organization_id' => $organization->id,
        'counsellor_id' => $counsellor->id,
        'status' => OrganizationCounsellorStatusEnum::active->value,
    ]);

    return [$organization, $payer, $counsellor];
}

test('personal-pay (no organizationId) is completely unaffected by the org-as-payer gate', function () {
    fakesPaystackForOrgPayer('personal_pay_ref');

    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $therapy = aPaidTherapyForOrgPayer($counsellor);

    $result = TransactionService::new()->initiateCharge(
        TransactionDTO::new()->fromArray(['user' => $therapy->addedby, 'for' => $therapy])
    );

    expect($result['transaction'])->toBeInstanceOf(Transaction::class);
    expect($result['transaction']->organization_id)->toBeNull();
});

test('a valid org-as-payer charge succeeds and records which organization financed it', function () {
    fakesPaystackForOrgPayer('org_pay_success_ref');

    [$organization, $payer, $counsellor] = anEligibleOrgPayerSetup();
    $therapy = aPaidTherapyForOrgPayer($counsellor, ['addedby_id' => $payer->id]);

    $result = TransactionService::new()->initiateCharge(
        TransactionDTO::new()->fromArray(['user' => $payer, 'for' => $therapy, 'organizationId' => $organization->id, 'organization' => $organization])
    );

    expect($result['transaction'])->toBeInstanceOf(Transaction::class);
    expect($result['transaction']->organization_id)->toBe($organization->id);
});

test('an organizationId that does not resolve to a real organization is rejected, not silently charged personally', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $therapy = aPaidTherapyForOrgPayer($counsellor);

    expect(fn () => TransactionService::new()->initiateCharge(
        TransactionDTO::new()->fromArray(['user' => $therapy->addedby, 'for' => $therapy, 'organizationId' => 999999])
    ))->toThrow(TransactionException::class, 'You are not authorized to pay for this via this organization.');

    $this->assertDatabaseMissing('transactions', ['for_type' => Therapy::class, 'for_id' => $therapy->id]);
});

test('a member with no affiliation to the named organization is rejected', function () {
    $organization = Organization::factory()->create(['is_provider' => true, 'is_consumer' => true, 'verified_at' => now()]);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $therapy = aPaidTherapyForOrgPayer($counsellor);

    expect(fn () => TransactionService::new()->initiateCharge(
        TransactionDTO::new()->fromArray(['user' => $therapy->addedby, 'for' => $therapy, 'organizationId' => $organization->id, 'organization' => $organization])
    ))->toThrow(TransactionException::class, 'You are not authorized to pay for this via this organization.');
});

test('a member whose affiliation to the organization has ended is rejected', function () {
    [$organization, $payer, $counsellor] = anEligibleOrgPayerSetup();
    OrganizationMember::query()->where('organization_id', $organization->id)->where('user_id', $payer->id)
        ->update(['status' => OrganizationMemberStatusEnum::ended->value]);
    $therapy = aPaidTherapyForOrgPayer($counsellor, ['addedby_id' => $payer->id]);

    expect(fn () => TransactionService::new()->initiateCharge(
        TransactionDTO::new()->fromArray(['user' => $payer, 'for' => $therapy, 'organizationId' => $organization->id, 'organization' => $organization])
    ))->toThrow(TransactionException::class, 'You are not authorized to pay for this via this organization.');
});

test('an unverified organization is rejected even with an otherwise-eligible member and counsellor', function () {
    [$organization, $payer, $counsellor] = anEligibleOrgPayerSetup(['verified_at' => null]);
    $therapy = aPaidTherapyForOrgPayer($counsellor, ['addedby_id' => $payer->id]);

    expect(fn () => TransactionService::new()->initiateCharge(
        TransactionDTO::new()->fromArray(['user' => $payer, 'for' => $therapy, 'organizationId' => $organization->id, 'organization' => $organization])
    ))->toThrow(TransactionException::class, 'You are not authorized to pay for this via this organization.');
});

test('a counsellor with only a pending (not active) affiliation to the organization is rejected', function () {
    [$organization, $payer, $counsellor] = anEligibleOrgPayerSetup();
    OrganizationCounsellor::query()->where('organization_id', $organization->id)->where('counsellor_id', $counsellor->id)
        ->update(['status' => OrganizationCounsellorStatusEnum::pending->value]);
    $therapy = aPaidTherapyForOrgPayer($counsellor, ['addedby_id' => $payer->id]);

    expect(fn () => TransactionService::new()->initiateCharge(
        TransactionDTO::new()->fromArray(['user' => $payer, 'for' => $therapy, 'organizationId' => $organization->id, 'organization' => $organization])
    ))->toThrow(TransactionException::class, 'You are not authorized to pay for this via this organization.');
});

test('a member on retainer billing is rejected with a specific, own-status message', function () {
    [$organization, $payer, $counsellor] = anEligibleOrgPayerSetup([], ['mode' => OrganizationMemberBillingModeEnum::retainer->value]);
    $therapy = aPaidTherapyForOrgPayer($counsellor, ['addedby_id' => $payer->id]);

    expect(fn () => TransactionService::new()->initiateCharge(
        TransactionDTO::new()->fromArray(['user' => $payer, 'for' => $therapy, 'organizationId' => $organization->id, 'organization' => $organization])
    ))->toThrow(TransactionException::class, 'Your organization covers this on a retainer basis -- no per-transaction payment is needed here.');
});

test('a member whose billing config excludes group therapies is rejected when paying for a GroupTherapy', function () {
    [$organization, $payer, $counsellor] = anEligibleOrgPayerSetup([], ['include_group_therapies' => false]);
    $groupTherapy = aPaidGroupTherapyForOrgPayer(['addedby_id' => $payer->id]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);

    expect(fn () => TransactionService::new()->initiateCharge(
        TransactionDTO::new()->fromArray(['user' => $payer, 'for' => $groupTherapy, 'organizationId' => $organization->id, 'organization' => $organization])
    ))->toThrow(TransactionException::class, 'Your organization billing does not cover group therapies.');
});

test('a GroupTherapy with every active counsellor covered by the organization succeeds', function () {
    fakesPaystackForOrgPayer('org_pay_group_all_covered_ref');

    [$organization, $payer, $firstCounsellor] = anEligibleOrgPayerSetup();
    $secondCounsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    OrganizationCounsellor::factory()->create([
        'organization_id' => $organization->id,
        'counsellor_id' => $secondCounsellor->id,
        'status' => OrganizationCounsellorStatusEnum::active->value,
    ]);

    $groupTherapy = aPaidGroupTherapyForOrgPayer(['addedby_id' => $payer->id]);
    $groupTherapy->counsellors()->attach([$firstCounsellor->id, $secondCounsellor->id], [
        'state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL',
    ]);

    $result = TransactionService::new()->initiateCharge(
        TransactionDTO::new()->fromArray(['user' => $payer, 'for' => $groupTherapy, 'organizationId' => $organization->id, 'organization' => $organization])
    );

    expect($result['transaction']->organization_id)->toBe($organization->id);
});

test('a GroupTherapy with one active counsellor not covered by the organization is rejected', function () {
    [$organization, $payer, $coveredCounsellor] = anEligibleOrgPayerSetup();
    $uncoveredCounsellor = Counsellor::factory()->create(['user_id' => User::factory()]);

    $groupTherapy = aPaidGroupTherapyForOrgPayer(['addedby_id' => $payer->id]);
    $groupTherapy->counsellors()->attach([$coveredCounsellor->id, $uncoveredCounsellor->id], [
        'state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL',
    ]);

    expect(fn () => TransactionService::new()->initiateCharge(
        TransactionDTO::new()->fromArray(['user' => $payer, 'for' => $groupTherapy, 'organizationId' => $organization->id, 'organization' => $organization])
    ))->toThrow(TransactionException::class, 'You are not authorized to pay for this via this organization.');
});

test('an inactive (removed) counsellor on a GroupTherapy does not need organization coverage', function () {
    fakesPaystackForOrgPayer('org_pay_group_inactive_counsellor_ignored_ref');

    [$organization, $payer, $coveredCounsellor] = anEligibleOrgPayerSetup();
    $removedCounsellor = Counsellor::factory()->create(['user_id' => User::factory()]);

    $groupTherapy = aPaidGroupTherapyForOrgPayer(['addedby_id' => $payer->id]);
    $groupTherapy->counsellors()->attach($coveredCounsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);
    $groupTherapy->counsellors()->attach($removedCounsellor->id, ['state' => CounsellorGroupTherapyStateEnum::inactive->value, 'role' => 'NORMAL']);

    $result = TransactionService::new()->initiateCharge(
        TransactionDTO::new()->fromArray(['user' => $payer, 'for' => $groupTherapy, 'organizationId' => $organization->id, 'organization' => $organization])
    );

    expect($result['transaction']->organization_id)->toBe($organization->id);
});
