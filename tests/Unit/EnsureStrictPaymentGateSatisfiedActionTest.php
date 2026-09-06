<?php

use App\Actions\Transaction\EnsureStrictPaymentGateSatisfiedAction;
use App\Enums\OrganizationCounsellorStatusEnum;
use App\Enums\OrganizationMemberBillingModeEnum;
use App\Exceptions\OrganizationBillingSuspendedException;
use App\Exceptions\PaymentRequiredException;
use App\Models\Counsellor;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\OrganizationMember;
use App\Models\OrganizationMemberBillingConfig;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;

// SCRUM-220/TT-7.5a: direct unit coverage of the shared action's own contract, independent of
// any one caller's wiring -- pins down all 4 per/session-presence combinations so a future
// caller can't silently reintroduce the exact "PER_THERAPY not gated when a $session happens to
// be passed" bug this ticket fixed (see MessageServiceStrictPaymentGateTest and
// EnsureUserHasAccessToTherapyActionStrictPaymentGateTest for the same matrix exercised through
// real call sites).

function gateTherapy(string $per): Therapy
{
    return Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'payment_type' => 'PAID',
        'payment_data' => ['per' => $per, 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60, 'strictPaymentGate' => true],
    ]);
}

test('PER_THERAPY with no session in context gates the therapy', function () {
    $user = User::factory()->create();
    $therapy = gateTherapy('PER_THERAPY');

    expect(fn () => EnsureStrictPaymentGateSatisfiedAction::new()->execute($therapy, $user))
        ->toThrow(PaymentRequiredException::class);
});

test('PER_THERAPY with a session in context still gates the therapy, not the session', function () {
    $user = User::factory()->create();
    $therapy = gateTherapy('PER_THERAPY');
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);

    expect(fn () => EnsureStrictPaymentGateSatisfiedAction::new()->execute($therapy, $user, $session))
        ->toThrow(PaymentRequiredException::class);

    $this->assertDatabaseMissing('payment_access_grants', [
        'for_type' => Session::class,
        'for_id' => $session->id,
    ]);
});

test('PER_SESSION with no session in context has nothing to gate', function () {
    $user = User::factory()->create();
    $therapy = gateTherapy('PER_SESSION');

    expect(fn () => EnsureStrictPaymentGateSatisfiedAction::new()->execute($therapy, $user))
        ->not->toThrow(PaymentRequiredException::class);

    $this->assertDatabaseCount('payment_access_grants', 0);
});

test('PER_SESSION with a session in context gates that session', function () {
    $user = User::factory()->create();
    $therapy = gateTherapy('PER_SESSION');
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);

    expect(fn () => EnsureStrictPaymentGateSatisfiedAction::new()->execute($therapy, $user, $session))
        ->toThrow(PaymentRequiredException::class);
});

// TT-7.3b-f1/SCRUM-237: a retainer-covered engagement never produces a Transaction at all
// (EnsureOrganizationCanPayForModelAction rejects that charge attempt outright), so without a
// bypass here a retainer-covered client on a strict-gated therapy could never satisfy the gate --
// a permanent lockout. These pin down exactly when that bypass does and doesn't apply.

function anActiveRetainerMembershipCovering(Counsellor $counsellor, User $user): OrganizationMember
{
    $organization = Organization::factory()->create(['is_consumer' => true, 'verified_at' => now()]);

    OrganizationCounsellor::factory()->create([
        'organization_id' => $organization->id,
        'counsellor_id' => $counsellor->id,
        'status' => OrganizationCounsellorStatusEnum::active->value,
    ]);

    $member = OrganizationMember::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
    ]);

    OrganizationMemberBillingConfig::factory()->create([
        'organization_member_id' => $member->id,
        'mode' => OrganizationMemberBillingModeEnum::retainer->value,
    ]);

    return $member;
}

test('a retainer-covered client is granted access to a strict-gated PER_THERAPY therapy without any Transaction', function () {
    $user = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $therapy = gateTherapy('PER_THERAPY');
    $therapy->update(['counsellor_id' => $counsellor->id]);
    anActiveRetainerMembershipCovering($counsellor, $user);

    expect(fn () => EnsureStrictPaymentGateSatisfiedAction::new()->execute($therapy, $user))
        ->not->toThrow(PaymentRequiredException::class);

    $this->assertDatabaseCount('payment_access_grants', 0);
});

test('a retainer-covered client is granted access to a strict-gated PER_SESSION session without any Transaction', function () {
    $user = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $therapy = gateTherapy('PER_SESSION');
    $therapy->update(['counsellor_id' => $counsellor->id]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);
    anActiveRetainerMembershipCovering($counsellor, $user);

    expect(fn () => EnsureStrictPaymentGateSatisfiedAction::new()->execute($therapy, $user, $session))
        ->not->toThrow(PaymentRequiredException::class);
});

test('a pay-per-use org member is NOT bypassed -- still gated normally', function () {
    $user = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $therapy = gateTherapy('PER_THERAPY');
    $therapy->update(['counsellor_id' => $counsellor->id]);
    $member = anActiveRetainerMembershipCovering($counsellor, $user);
    // Flip to pay-per-use -- the bypass must only ever apply to genuinely retainer-mode members.
    OrganizationMemberBillingConfig::factory()->create([
        'organization_member_id' => $member->id,
        'mode' => OrganizationMemberBillingModeEnum::payPerUse->value,
        'effective_from' => now()->addMinute(),
    ]);

    expect(fn () => EnsureStrictPaymentGateSatisfiedAction::new()->execute($therapy, $user))
        ->toThrow(PaymentRequiredException::class);
});

test('an unverified organization does not grant the retainer bypass', function () {
    $user = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $therapy = gateTherapy('PER_THERAPY');
    $therapy->update(['counsellor_id' => $counsellor->id]);
    $organization = Organization::factory()->create(['is_consumer' => true, 'verified_at' => null]);
    OrganizationCounsellor::factory()->create([
        'organization_id' => $organization->id,
        'counsellor_id' => $counsellor->id,
        'status' => OrganizationCounsellorStatusEnum::active->value,
    ]);
    $member = OrganizationMember::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id]);
    OrganizationMemberBillingConfig::factory()->create(['organization_member_id' => $member->id]);

    expect(fn () => EnsureStrictPaymentGateSatisfiedAction::new()->execute($therapy, $user))
        ->toThrow(PaymentRequiredException::class);
});

// TT-7.3b-f2/SCRUM-238: layered on top of the retainer bypass above -- a suspended org's member
// must be BLOCKED entirely, never fall through to the personal-pay checks below (no personal-pay
// fallback is offered; the org, not the member, must resolve the suspension).

function anActiveRetainerMembershipCoveringWithOrg(Counsellor $counsellor, User $user): array
{
    $organization = Organization::factory()->create(['is_consumer' => true, 'verified_at' => now()]);

    OrganizationCounsellor::factory()->create([
        'organization_id' => $organization->id,
        'counsellor_id' => $counsellor->id,
        'status' => OrganizationCounsellorStatusEnum::active->value,
    ]);

    $member = OrganizationMember::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
    ]);

    OrganizationMemberBillingConfig::factory()->create([
        'organization_member_id' => $member->id,
        'mode' => OrganizationMemberBillingModeEnum::retainer->value,
    ]);

    return [$member, $organization];
}

test('a retainer-covered client whose organization is billing-suspended is blocked, not bypassed', function () {
    $user = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $therapy = gateTherapy('PER_THERAPY');
    $therapy->update(['counsellor_id' => $counsellor->id]);
    [, $organization] = anActiveRetainerMembershipCoveringWithOrg($counsellor, $user);
    $organization->suspendBilling('Retainer invoice settlement failed.');

    expect(fn () => EnsureStrictPaymentGateSatisfiedAction::new()->execute($therapy, $user))
        ->toThrow(OrganizationBillingSuspendedException::class);

    // Never falls through to the personal-pay path -- no grant, no PaymentRequiredException
    // either (a different exception type, so a caller that only catches PaymentRequiredException
    // must not accidentally treat this as "pay to continue").
    $this->assertDatabaseCount('payment_access_grants', 0);
});

test('a retainer-covered client whose organization is NOT suspended is unaffected', function () {
    $user = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $therapy = gateTherapy('PER_THERAPY');
    $therapy->update(['counsellor_id' => $counsellor->id]);
    anActiveRetainerMembershipCoveringWithOrg($counsellor, $user);

    expect(fn () => EnsureStrictPaymentGateSatisfiedAction::new()->execute($therapy, $user))
        ->not->toThrow(OrganizationBillingSuspendedException::class);
});

// Reviewer finding: suspension is read directly off the resolved covering Organization instance
// (a plain column read, no shared/static state), so this pins down there is genuinely no
// cross-org leakage -- suspending org A must never affect org B's own, otherwise-identical
// retainer coverage.
test('suspending one organization does not affect a different organization\'s own retainer-covered member', function () {
    $counsellorA = Counsellor::factory()->create(['user_id' => User::factory()]);
    $userA = User::factory()->create();
    $therapyA = gateTherapy('PER_THERAPY');
    $therapyA->update(['counsellor_id' => $counsellorA->id]);
    [, $organizationA] = anActiveRetainerMembershipCoveringWithOrg($counsellorA, $userA);
    $organizationA->suspendBilling('Retainer invoice settlement failed.');

    $counsellorB = Counsellor::factory()->create(['user_id' => User::factory()]);
    $userB = User::factory()->create();
    $therapyB = gateTherapy('PER_THERAPY');
    $therapyB->update(['counsellor_id' => $counsellorB->id]);
    anActiveRetainerMembershipCoveringWithOrg($counsellorB, $userB);

    expect(fn () => EnsureStrictPaymentGateSatisfiedAction::new()->execute($therapyA, $userA))
        ->toThrow(OrganizationBillingSuspendedException::class);

    expect(fn () => EnsureStrictPaymentGateSatisfiedAction::new()->execute($therapyB, $userB))
        ->not->toThrow(OrganizationBillingSuspendedException::class);
});

test('a retainer membership covering a different counsellor does not grant the bypass', function () {
    $user = User::factory()->create();
    $coveredCounsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $unrelatedCounsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $therapy = gateTherapy('PER_THERAPY');
    $therapy->update(['counsellor_id' => $unrelatedCounsellor->id]);
    anActiveRetainerMembershipCovering($coveredCounsellor, $user);

    expect(fn () => EnsureStrictPaymentGateSatisfiedAction::new()->execute($therapy, $user))
        ->toThrow(PaymentRequiredException::class);
});
