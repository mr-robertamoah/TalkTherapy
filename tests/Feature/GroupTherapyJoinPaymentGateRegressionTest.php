<?php

use App\Actions\GroupTherapy\JoinGroupTherapyAction;
use App\Actions\Request\RespondToGroupTherapyMembershipRequestAction;
use App\DTOs\JoinGroupTherapyDTO;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestStatusEnum;
use App\Models\GroupTherapy;
use App\Models\Request as RequestModel;
use App\Models\Transaction;
use App\Models\User;

// TT-7.5b-b4/SCRUM-268: locks in the user-approved decision that payment is required only for
// CONTENT access, never for joining itself -- JoinGroupTherapyAction stays entirely
// payment-unaware, matching TT-7.5a's own precedent (a Therapy is never gated at creation or
// assistance-request-acceptance time, only at content access via
// EnsureUserHasAccessToTherapyAction/EnsureUserCanAccessTherapyContentAction). This ticket is a
// confirmation/regression pass -- no production code change, per its own scope.

function strictGatedPaidGroupTherapyForJoin(User $creator, array $overrides = []): GroupTherapy
{
    return GroupTherapy::factory()->create(array_merge([
        'addedby_type' => User::class,
        'addedby_id' => $creator->id,
        'anonymous' => false,
        'max_users' => 5,
        'payment_type' => 'PAID',
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60, 'strictPaymentGate' => true, 'allowFreeHistoricalAccess' => true],
    ], $overrides));
}

test('immediate join (allow_anyone true) succeeds on a strict-gated, unpaid group therapy', function () {
    $creator = User::factory()->create(['dob' => now()->subYears(25)]);
    $groupTherapy = strictGatedPaidGroupTherapyForJoin($creator, ['allow_anyone' => true]);
    $joiner = User::factory()->create(['dob' => now()->subYears(25)]);

    $result = JoinGroupTherapyAction::new()->execute(
        JoinGroupTherapyDTO::new()->fromArray([
            'user' => $joiner,
            'groupTherapy' => $groupTherapy,
            'anonymous' => false,
        ])
    );

    expect($result)->toBeInstanceOf(GroupTherapy::class);
    expect($groupTherapy->users()->whereKey($joiner->id)->exists())->toBeTrue();
});

test('a membership request for a strict-gated, unpaid group therapy can be sent and accepted', function () {
    $creator = User::factory()->create(['dob' => now()->subYears(25)]);
    $groupTherapy = strictGatedPaidGroupTherapyForJoin($creator, ['allow_anyone' => false]);
    $requester = User::factory()->create(['dob' => now()->subYears(25)]);

    $request = JoinGroupTherapyAction::new()->execute(
        JoinGroupTherapyDTO::new()->fromArray([
            'user' => $requester,
            'groupTherapy' => $groupTherapy,
            'anonymous' => false,
        ])
    );

    expect($request)->toBeInstanceOf(RequestModel::class);

    RespondToGroupTherapyMembershipRequestAction::new()->execute(
        RequestResponseDTO::new()->fromArray([
            'user' => $creator,
            'response' => 'accepted',
            'request' => $request,
        ])
    );

    expect($request->fresh()->status)->toBe(RequestStatusEnum::accepted->value)
        ->and($groupTherapy->users()->whereKey($requester->id)->exists())->toBeTrue();
});

// The counterpart to the above two: a strict-gated group with a successful transaction on file
// changes nothing about the join path either -- joining was never reading payment state at all.
test('joining a strict-gated group is unaffected by an existing successful transaction for a different user', function () {
    $creator = User::factory()->create(['dob' => now()->subYears(25)]);
    $groupTherapy = strictGatedPaidGroupTherapyForJoin($creator, ['allow_anyone' => true]);
    $payingMember = User::factory()->create(['dob' => now()->subYears(25)]);
    Transaction::factory()->create([
        'for_type' => GroupTherapy::class,
        'for_id' => $groupTherapy->id,
        'user_id' => $payingMember->id,
        'status' => 'SUCCESS',
    ]);
    $newJoiner = User::factory()->create(['dob' => now()->subYears(25)]);

    $result = JoinGroupTherapyAction::new()->execute(
        JoinGroupTherapyDTO::new()->fromArray([
            'user' => $newJoiner,
            'groupTherapy' => $groupTherapy,
            'anonymous' => false,
        ])
    );

    expect($result)->toBeInstanceOf(GroupTherapy::class);
    expect($groupTherapy->users()->whereKey($newJoiner->id)->exists())->toBeTrue();
});
