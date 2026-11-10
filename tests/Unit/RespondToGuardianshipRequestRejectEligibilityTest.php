<?php

use App\Actions\Request\CreateRequestAction;
use App\Actions\Request\RespondToGuardianshipRequestAction;
use App\DTOs\CreateRequestDTO;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Exceptions\UserException;
use App\Models\Request;
use App\Models\User;

// SCRUM-299: EnsureUserCanBeGuardianAction's eligibility check previously ran unconditionally in
// RespondToGuardianshipRequestAction, before branching on accept vs. reject -- so a recipient who
// doesn't currently qualify as a guardian got a 422 even when trying to REJECT an unwanted
// request, not just when accepting one. Rejecting a request one didn't ask for and doesn't
// qualify for must always be allowed regardless of eligibility.

function anIneligibleGuardianshipRequest(): Request
{
    $ward = User::factory()->create();
    // No dob + unverified email -- fails every branch of EnsureUserCanBeGuardianAction's check.
    $ineligibleRecipient = User::factory()->create(['dob' => null, 'email_verified_at' => null]);

    return CreateRequestAction::new()->execute(CreateRequestDTO::new()->fromArray([
        'from' => $ward,
        'to' => $ineligibleRecipient,
        'for' => $ward,
        'type' => RequestTypeEnum::guardianship->value,
    ]));
}

// The real payload shape: every actual caller (RequestBadge.vue's clickedResponse('rejected'),
// and every other RespondTo*RequestAction's own test suite) sends the explicit string 'rejected',
// never a null response -- this must be the primary case, not just the implicit-null one below.
test('an ineligible recipient CAN reject a guardianship request via the real "rejected" payload', function () {
    $request = anIneligibleGuardianshipRequest();

    RespondToGuardianshipRequestAction::new()->execute(RequestResponseDTO::new()->fromArray([
        'user' => $request->to,
        'response' => 'rejected',
        'request' => $request,
    ]));

    expect($request->fresh()->status)->toBe(RequestStatusEnum::rejected->value);
});

test('an ineligible recipient CAN also reject via an implicit null response', function () {
    $request = anIneligibleGuardianshipRequest();

    RespondToGuardianshipRequestAction::new()->execute(RequestResponseDTO::new()->fromArray([
        'user' => $request->to,
        'response' => null,
        'request' => $request,
    ]));

    expect($request->fresh()->status)->toBe(RequestStatusEnum::rejected->value);
});

test('an ineligible recipient still CANNOT accept a guardianship request', function () {
    $request = anIneligibleGuardianshipRequest();

    expect(fn () => RespondToGuardianshipRequestAction::new()->execute(RequestResponseDTO::new()->fromArray([
        'user' => $request->to,
        'response' => 'accepted',
        'request' => $request,
    ])))->toThrow(UserException::class);

    expect($request->fresh()->status)->toBe(RequestStatusEnum::pending->value);
});
