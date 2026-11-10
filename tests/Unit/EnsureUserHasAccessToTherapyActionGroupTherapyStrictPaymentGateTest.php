<?php

use App\Actions\Therapy\EnsureUserHasAccessToTherapyAction;
use App\DTOs\GetTherapyDTO;
use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Exceptions\PaymentRequiredException;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\PaymentAccessGrant;
use App\Models\Transaction;
use App\Models\User;

// TT-7.5b-b2/SCRUM-266: page-load enforcement for GroupTherapy, mirroring
// EnsureUserHasAccessToTherapyActionStrictPaymentGateTest.php's own Therapy matrix, plus the
// GroupTherapy-specific concerns: every member (not just one addedby) is independently gated,
// and any active counsellor on the group -- not just a single one -- is exempt.

function strictGatedPaidGroupTherapy(array $overrides = []): GroupTherapy
{
    return GroupTherapy::factory()->create(array_merge([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'public' => false,
        'payment_type' => 'PAID',
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60, 'strictPaymentGate' => true],
    ], $overrides));
}

function dtoFor(GroupTherapy $groupTherapy, ?User $user): GetTherapyDTO
{
    return GetTherapyDTO::new()->fromArray(['user' => $user, 'groupTherapy' => $groupTherapy]);
}

test('a joined member with no grant and no successful transaction is denied access with PaymentRequiredException', function () {
    $groupTherapy = strictGatedPaidGroupTherapy();
    $member = User::factory()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false]);

    expect(fn () => EnsureUserHasAccessToTherapyAction::new()->execute(dtoFor($groupTherapy, $member), 'groupTherapy'))
        ->toThrow(PaymentRequiredException::class);

    $this->assertDatabaseCount('payment_access_grants', 0);
});

test('a joined member with a successful transaction is granted access and a grant is persisted', function () {
    $groupTherapy = strictGatedPaidGroupTherapy();
    $member = User::factory()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false]);
    Transaction::factory()->create([
        'for_type' => GroupTherapy::class,
        'for_id' => $groupTherapy->id,
        'user_id' => $member->id,
        'status' => 'SUCCESS',
    ]);

    EnsureUserHasAccessToTherapyAction::new()->execute(dtoFor($groupTherapy, $member), 'groupTherapy');

    $this->assertDatabaseHas('payment_access_grants', [
        'user_id' => $member->id,
        'for_type' => GroupTherapy::class,
        'for_id' => $groupTherapy->id,
    ]);
});

// The group's own creator (addedby, User type) is a member too -- even though they hold no
// group_therapy_user pivot row (see GetGroupTherapyPaymentRosterAction's own identical fix).
test('the group\'s own User-type creator (addedby) is gated as a member, with no pivot row needed', function () {
    $creator = User::factory()->create();
    $groupTherapy = strictGatedPaidGroupTherapy(['addedby_id' => $creator->id]);

    expect(fn () => EnsureUserHasAccessToTherapyAction::new()->execute(dtoFor($groupTherapy, $creator), 'groupTherapy'))
        ->toThrow(PaymentRequiredException::class);
});

// Two members' gate outcomes are independent -- the defining behavior of a multi-payer model
// that individual Therapy never had to prove.
test('one member paying does not unlock access for a different member', function () {
    $groupTherapy = strictGatedPaidGroupTherapy();
    $payingMember = User::factory()->create();
    $unpaidMember = User::factory()->create();
    $groupTherapy->users()->attach([$payingMember->id, $unpaidMember->id], ['anonymous' => false]);
    Transaction::factory()->create([
        'for_type' => GroupTherapy::class,
        'for_id' => $groupTherapy->id,
        'user_id' => $payingMember->id,
        'status' => 'SUCCESS',
    ]);

    EnsureUserHasAccessToTherapyAction::new()->execute(dtoFor($groupTherapy, $payingMember), 'groupTherapy');

    expect(fn () => EnsureUserHasAccessToTherapyAction::new()->execute(dtoFor($groupTherapy, $unpaidMember), 'groupTherapy'))
        ->toThrow(PaymentRequiredException::class);
});

test('any active counsellor on the group is never subject to the payment gate', function () {
    $groupTherapy = strictGatedPaidGroupTherapy();
    $counsellorUserA = User::factory()->create();
    $counsellorA = Counsellor::factory()->create(['user_id' => $counsellorUserA->id]);
    $counsellorUserB = User::factory()->create();
    $counsellorB = Counsellor::factory()->create(['user_id' => $counsellorUserB->id]);
    $groupTherapy->counsellors()->attach([$counsellorA->id, $counsellorB->id], ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);

    expect(fn () => EnsureUserHasAccessToTherapyAction::new()->execute(dtoFor($groupTherapy, $counsellorUserA), 'groupTherapy'))
        ->not->toThrow(PaymentRequiredException::class);
    expect(fn () => EnsureUserHasAccessToTherapyAction::new()->execute(dtoFor($groupTherapy, $counsellorUserB), 'groupTherapy'))
        ->not->toThrow(PaymentRequiredException::class);

    $this->assertDatabaseCount('payment_access_grants', 0);
});

// A counsellor who ALSO happens to be a joined member (e.g. joined before being assigned as
// counsellor) must still be exempt -- b0's "counsellors run the group, they don't pay into it"
// precedent applies regardless of how they're attached.
test('a counsellor who also holds a group_therapy_user pivot row is still never gated', function () {
    $groupTherapy = strictGatedPaidGroupTherapy();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);
    $groupTherapy->users()->attach($counsellorUser->id, ['anonymous' => false]);

    expect(fn () => EnsureUserHasAccessToTherapyAction::new()->execute(dtoFor($groupTherapy, $counsellorUser), 'groupTherapy'))
        ->not->toThrow(PaymentRequiredException::class);

    $this->assertDatabaseCount('payment_access_grants', 0);
});

// An INACTIVE (removed) counsellor is not exempt -- b0's active-only precedent.
test('an inactive (removed) counsellor is still subject to the payment gate if also a joined member', function () {
    $groupTherapy = strictGatedPaidGroupTherapy();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::inactive->value, 'role' => 'NORMAL']);
    $groupTherapy->users()->attach($counsellorUser->id, ['anonymous' => false]);

    expect(fn () => EnsureUserHasAccessToTherapyAction::new()->execute(dtoFor($groupTherapy, $counsellorUser), 'groupTherapy'))
        ->toThrow(PaymentRequiredException::class);
});

test('an admin is never subject to the payment gate on a strict-gated group therapy', function () {
    $admin = User::factory()->has(Administrator::factory())->create();
    $groupTherapy = strictGatedPaidGroupTherapy();

    expect(fn () => EnsureUserHasAccessToTherapyAction::new()->execute(dtoFor($groupTherapy, $admin), 'groupTherapy'))
        ->not->toThrow(PaymentRequiredException::class);
});

test('a public strict-gated group therapy is still visible to a non-participant, unaffected by the payment gate', function () {
    $groupTherapy = strictGatedPaidGroupTherapy(['public' => true]);
    $unrelatedUser = User::factory()->create();

    expect(fn () => EnsureUserHasAccessToTherapyAction::new()->execute(dtoFor($groupTherapy, $unrelatedUser), 'groupTherapy'))
        ->not->toThrow(PaymentRequiredException::class);
});

test('a trust-based (non-strict) paid group therapy is unaffected -- a member is granted access with no transaction at all', function () {
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'public' => false,
        'payment_type' => 'PAID',
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60, 'strictPaymentGate' => false],
    ]);
    $member = User::factory()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false]);

    expect(fn () => EnsureUserHasAccessToTherapyAction::new()->execute(dtoFor($groupTherapy, $member), 'groupTherapy'))
        ->not->toThrow(PaymentRequiredException::class);

    $this->assertDatabaseCount('payment_access_grants', 0);
});

test('a grant belonging to a different group therapy or a different member never satisfies the gate', function () {
    $groupTherapy = strictGatedPaidGroupTherapy();
    $member = User::factory()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false]);

    $otherGroupTherapy = strictGatedPaidGroupTherapy();
    $otherMember = User::factory()->create();

    PaymentAccessGrant::factory()->create([
        'user_id' => $otherMember->id,
        'for_type' => GroupTherapy::class,
        'for_id' => $groupTherapy->id,
    ]);
    PaymentAccessGrant::factory()->create([
        'user_id' => $member->id,
        'for_type' => GroupTherapy::class,
        'for_id' => $otherGroupTherapy->id,
    ]);

    expect(fn () => EnsureUserHasAccessToTherapyAction::new()->execute(dtoFor($groupTherapy, $member), 'groupTherapy'))
        ->toThrow(PaymentRequiredException::class);
});
