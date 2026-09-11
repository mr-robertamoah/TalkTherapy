<?php

use App\Actions\Transaction\EnsureStrictPaymentGateSatisfiedAction;
use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Enums\OrganizationCounsellorStatusEnum;
use App\Enums\OrganizationMemberBillingModeEnum;
use App\Exceptions\PaymentRequiredException;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\OrganizationMember;
use App\Models\OrganizationMemberBillingConfig;
use App\Models\Session;
use App\Models\Transaction;
use App\Models\User;

// TT-7.5b-b2/SCRUM-266: widened coverage for GroupTherapy -- mirrors
// EnsureStrictPaymentGateSatisfiedActionTest.php's own Therapy matrix, plus the one behavior that
// genuinely only exists for a multi-payer model: two members' gate outcomes are independent of
// each other.

function gateGroupTherapy(string $per): GroupTherapy
{
    return GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'payment_type' => 'PAID',
        'payment_data' => ['per' => $per, 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60, 'strictPaymentGate' => true],
    ]);
}

test('PER_THERAPY with no session in context gates the group therapy', function () {
    $member = User::factory()->create();
    $groupTherapy = gateGroupTherapy('PER_THERAPY');

    expect(fn () => EnsureStrictPaymentGateSatisfiedAction::new()->execute($groupTherapy, $member))
        ->toThrow(PaymentRequiredException::class);
});

test('a member with a successful transaction is granted access and a grant scoped to the GroupTherapy is persisted', function () {
    $member = User::factory()->create();
    $groupTherapy = gateGroupTherapy('PER_THERAPY');
    Transaction::factory()->create([
        'for_type' => GroupTherapy::class,
        'for_id' => $groupTherapy->id,
        'user_id' => $member->id,
        'status' => 'SUCCESS',
    ]);

    // Deliberately not expect(...)->not->toThrow(PaymentRequiredException::class) here -- that
    // assertion is blind to any OTHER exception type (e.g. a TypeError from an insufficiently
    // widened DTO elsewhere in the grant path), which would make the test pass for the wrong
    // reason. Calling directly lets any exception fail this test.
    EnsureStrictPaymentGateSatisfiedAction::new()->execute($groupTherapy, $member);

    $this->assertDatabaseHas('payment_access_grants', [
        'user_id' => $member->id,
        'for_type' => GroupTherapy::class,
        'for_id' => $groupTherapy->id,
    ]);
});

// The behavior that only exists for a multi-payer model: one member paying must never unlock
// access for a different member on the same group -- each member's standing is independent.
test('one member\'s successful payment does not satisfy the gate for a different member', function () {
    $payingMember = User::factory()->create();
    $otherMember = User::factory()->create();
    $groupTherapy = gateGroupTherapy('PER_THERAPY');
    Transaction::factory()->create([
        'for_type' => GroupTherapy::class,
        'for_id' => $groupTherapy->id,
        'user_id' => $payingMember->id,
        'status' => 'SUCCESS',
    ]);

    expect(fn () => EnsureStrictPaymentGateSatisfiedAction::new()->execute($groupTherapy, $payingMember))
        ->not->toThrow(PaymentRequiredException::class);

    expect(fn () => EnsureStrictPaymentGateSatisfiedAction::new()->execute($groupTherapy, $otherMember))
        ->toThrow(PaymentRequiredException::class);
});

test('PER_SESSION with a session in context gates that session for GroupTherapy too', function () {
    $member = User::factory()->create();
    $groupTherapy = gateGroupTherapy('PER_SESSION');
    $session = Session::factory()->create(['for_id' => $groupTherapy->id, 'for_type' => GroupTherapy::class]);

    expect(fn () => EnsureStrictPaymentGateSatisfiedAction::new()->execute($groupTherapy, $member, $session))
        ->toThrow(PaymentRequiredException::class);
});

// TT-7.5b-b2's own mandatory widening fix: without it, this call would throw a hard TypeError
// (GetRetainerCoveringOrganizationAction reading GroupTherapy::$counsellor, which doesn't exist)
// rather than resolving the retainer bypass correctly.
test('a member covered by a retainer on one of the group\'s active counsellors is granted access without any Transaction', function () {
    $member = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $groupTherapy = gateGroupTherapy('PER_THERAPY');
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);

    $organization = Organization::factory()->create(['is_consumer' => true, 'verified_at' => now()]);
    OrganizationCounsellor::factory()->create([
        'organization_id' => $organization->id,
        'counsellor_id' => $counsellor->id,
        'status' => OrganizationCounsellorStatusEnum::active->value,
    ]);
    $orgMember = OrganizationMember::factory()->create(['organization_id' => $organization->id, 'user_id' => $member->id]);
    OrganizationMemberBillingConfig::factory()->create([
        'organization_member_id' => $orgMember->id,
        'mode' => OrganizationMemberBillingModeEnum::retainer->value,
    ]);

    expect(fn () => EnsureStrictPaymentGateSatisfiedAction::new()->execute($groupTherapy, $member))
        ->not->toThrow(PaymentRequiredException::class);

    $this->assertDatabaseCount('payment_access_grants', 0);
});

test('a trust-based (non-strict) paid group therapy is unaffected', function () {
    $member = User::factory()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'payment_type' => 'PAID',
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60, 'strictPaymentGate' => false],
    ]);

    expect(fn () => EnsureStrictPaymentGateSatisfiedAction::new()->execute($groupTherapy, $member))
        ->not->toThrow(PaymentRequiredException::class);

    $this->assertDatabaseCount('payment_access_grants', 0);
});
