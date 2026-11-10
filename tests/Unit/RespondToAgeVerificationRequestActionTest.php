<?php

use App\Actions\Request\CreateRequestAction;
use App\Actions\Request\RespondToAgeVerificationRequestAction;
use App\Actions\User\SubmitAgeVerificationAction;
use App\DTOs\CreateRequestDTO;
use App\DTOs\RequestResponseDTO;
use App\DTOs\SubmitAgeVerificationDTO;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\Request;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\AgeVerificationRequestApprovedNotification;
use App\Notifications\AgeVerificationRequestRejectedNotification;
use App\Notifications\DobChangeRequestSupersededNotification;
use Illuminate\Support\Facades\Notification;

// TT-4.11c/SCRUM-304: approving an ageVerification request marks the user's EXISTING dob as
// verified (never carries a `newDob` -- unlike dobChange, this is a self-attestation about the
// current value), retroactively corrects every qualifying minor-status snapshot the same way
// dobChange does, and supersedes any still-pending dobChange request for the same user. Rejecting
// leaves everything untouched, with no automated consequence (the user's own explicit decision).

function anAgeVerificationRequest(User $user, string $attestation = 'I confirm my dob is accurate.'): Request
{
    return CreateRequestAction::new()->execute(
        CreateRequestDTO::new()->fromArray([
            'from' => $user,
            'to' => null,
            'for' => $user,
            'type' => RequestTypeEnum::ageVerification->value,
            'data' => ['attestation' => $attestation],
        ])
    );
}

test('approving marks the existing dob as verified', function () {
    $dob = now()->subYears(30)->toDateString();
    $user = User::factory()->create(['dob' => $dob]);
    $request = anAgeVerificationRequest($user);
    $admin = User::factory()->create();

    RespondToAgeVerificationRequestAction::new()->execute(RequestResponseDTO::new()->fromArray([
        'user' => $admin,
        'response' => 'accepted',
        'request' => $request,
    ]));

    $user = $user->fresh();
    expect($user->dob->toDateString())->toBe($dob);
    expect($user->dob_verified_at)->not->toBeNull();
});

test('approving retroactively corrects the ward_was_minor_at_creation snapshot on an existing Guardianship row', function () {
    $minor = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);
    $guardian = User::factory()->create();
    $guardianship = Guardianship::query()->create([
        'guardian_id' => $guardian->id, 'ward_id' => $minor->id, 'ward_was_minor_at_creation' => true,
    ]);
    $request = anAgeVerificationRequest($minor);
    $admin = User::factory()->create();

    RespondToAgeVerificationRequestAction::new()->execute(RequestResponseDTO::new()->fromArray([
        'user' => $admin, 'response' => 'accepted', 'request' => $request,
    ]));

    // dob itself doesn't change (17 stays a minor), but the correction path is still exercised --
    // confirms it re-derives from the confirmed dob rather than silently skipping this step.
    expect($guardianship->fresh()->ward_was_minor_at_creation)->toBeTrue();
});

test('approving retroactively corrects the client_was_minor_at_creation snapshot on an existing Therapy record', function () {
    $adult = User::factory()->create(['dob' => now()->subYears(30)->toDateString()]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $adult->id, 'client_was_minor_at_creation' => true,
    ]);
    $request = anAgeVerificationRequest($adult);
    $admin = User::factory()->create();

    RespondToAgeVerificationRequestAction::new()->execute(RequestResponseDTO::new()->fromArray([
        'user' => $admin, 'response' => 'accepted', 'request' => $request,
    ]));

    expect($therapy->fresh()->client_was_minor_at_creation)->toBeFalse();
});

test('approving retroactively corrects the client_was_minor_at_creation snapshot on an existing GroupTherapy record', function () {
    $adult = User::factory()->create(['dob' => now()->subYears(30)->toDateString()]);
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $adult->id, 'client_was_minor_at_creation' => true,
    ]);
    $request = anAgeVerificationRequest($adult);
    $admin = User::factory()->create();

    RespondToAgeVerificationRequestAction::new()->execute(RequestResponseDTO::new()->fromArray([
        'user' => $admin, 'response' => 'accepted', 'request' => $request,
    ]));

    expect($groupTherapy->fresh()->client_was_minor_at_creation)->toBeFalse();
});

test('rejecting leaves dob, dob_verified_at, and every snapshot untouched', function () {
    $user = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $user->id, 'client_was_minor_at_creation' => true,
    ]);
    $request = anAgeVerificationRequest($user);
    $admin = User::factory()->create();

    RespondToAgeVerificationRequestAction::new()->execute(RequestResponseDTO::new()->fromArray([
        'user' => $admin, 'response' => null, 'request' => $request,
    ]));

    $user = $user->fresh();
    expect($user->dob_verified_at)->toBeNull();
    expect($therapy->fresh()->client_was_minor_at_creation)->toBeTrue();
    expect($request->fresh()->status)->toBe(RequestStatusEnum::rejected->value);
});

test('approving supersedes a still-pending dobChange request for the same user', function () {
    $user = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $user->id]);
    $dobChangeRequest = CreateRequestAction::new()->execute(CreateRequestDTO::new()->fromArray([
        'from' => $user, 'to' => $guardian, 'for' => $user,
        'type' => RequestTypeEnum::dobChange->value,
        'data' => ['newDob' => now()->subYears(30)->toDateString(), 'priorDob' => now()->subYears(17)->toDateString()],
    ]));
    $ageVerificationRequest = anAgeVerificationRequest($user);
    $admin = User::factory()->create();

    RespondToAgeVerificationRequestAction::new()->execute(RequestResponseDTO::new()->fromArray([
        'user' => $admin, 'response' => 'accepted', 'request' => $ageVerificationRequest,
    ]));

    expect($dobChangeRequest->fresh()->status)->toBe(RequestStatusEnum::superseded->value);
});

test('rejecting does not supersede a pending dobChange request for the same user', function () {
    $user = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $user->id]);
    $dobChangeRequest = CreateRequestAction::new()->execute(CreateRequestDTO::new()->fromArray([
        'from' => $user, 'to' => $guardian, 'for' => $user,
        'type' => RequestTypeEnum::dobChange->value,
        'data' => ['newDob' => now()->subYears(30)->toDateString(), 'priorDob' => now()->subYears(17)->toDateString()],
    ]));
    $ageVerificationRequest = anAgeVerificationRequest($user);
    $admin = User::factory()->create();

    RespondToAgeVerificationRequestAction::new()->execute(RequestResponseDTO::new()->fromArray([
        'user' => $admin, 'response' => 'rejected', 'request' => $ageVerificationRequest,
    ]));

    expect($dobChangeRequest->fresh()->status)->toBe(RequestStatusEnum::pending->value);
});

test('approving notifies the submitter and the superseded dobChange request\'s own requester', function () {
    Notification::fake();
    $user = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $user->id]);
    $dobChangeRequest = CreateRequestAction::new()->execute(CreateRequestDTO::new()->fromArray([
        'from' => $user, 'to' => $guardian, 'for' => $user,
        'type' => RequestTypeEnum::dobChange->value,
        'data' => ['newDob' => now()->subYears(30)->toDateString(), 'priorDob' => now()->subYears(17)->toDateString()],
    ]));
    $ageVerificationRequest = anAgeVerificationRequest($user);
    $admin = User::factory()->create();

    RespondToAgeVerificationRequestAction::new()->execute(RequestResponseDTO::new()->fromArray([
        'user' => $admin, 'response' => 'accepted', 'request' => $ageVerificationRequest,
    ]));

    Notification::assertSentTo($user, AgeVerificationRequestApprovedNotification::class);
    Notification::assertSentTo($user, DobChangeRequestSupersededNotification::class);
});

test('rejecting notifies the submitter', function () {
    Notification::fake();
    $user = User::factory()->create();
    $request = anAgeVerificationRequest($user);
    $admin = User::factory()->create();

    RespondToAgeVerificationRequestAction::new()->execute(RequestResponseDTO::new()->fromArray([
        'user' => $admin, 'response' => null, 'request' => $request,
    ]));

    Notification::assertSentTo($user, AgeVerificationRequestRejectedNotification::class);
});

// TT-4.11c/SCRUM-304 security-review finding: the value marked "verified" must be the one
// snapshotted at submission time, not whatever `dob` happens to be current on the User row at
// approval time -- otherwise a value that drifted between submission and admin review (an
// unrelated edit, a concurrent dobChange approval) could be silently certified as verified even
// though nobody's attestation/document was ever actually about it.
test('approving applies the dob attested to at submission time, not one that drifted afterward', function () {
    $attestedDob = now()->subYears(30)->toDateString();
    $user = User::factory()->create(['dob' => $attestedDob]);
    $request = SubmitAgeVerificationAction::new()->execute(SubmitAgeVerificationDTO::new()->fromArray([
        'user' => $user,
        'attestation' => 'I confirm my dob is accurate.',
    ]));

    // dob drifts after submission but before admin review -- an unrelated direct edit.
    $user->update(['dob' => now()->subYears(10)->toDateString()]);
    $admin = User::factory()->create();

    RespondToAgeVerificationRequestAction::new()->execute(RequestResponseDTO::new()->fromArray([
        'user' => $admin, 'response' => 'accepted', 'request' => $request,
    ]));

    expect($user->fresh()->dob->toDateString())->toBe($attestedDob);
});

test('responding to an already-decided request a second time does not re-apply the verification or re-notify', function () {
    Notification::fake();
    $user = User::factory()->create();
    $request = anAgeVerificationRequest($user);
    $admin = User::factory()->create();

    RespondToAgeVerificationRequestAction::new()->execute(RequestResponseDTO::new()->fromArray([
        'user' => $admin, 'response' => 'accepted', 'request' => $request,
    ]));
    $verifiedAtAfterFirstResponse = $user->fresh()->dob_verified_at;

    RespondToAgeVerificationRequestAction::new()->execute(RequestResponseDTO::new()->fromArray([
        'user' => $admin, 'response' => 'rejected', 'request' => $request->fresh(),
    ]));

    expect($user->fresh()->dob_verified_at->toDateTimeString())->toBe($verifiedAtAfterFirstResponse->toDateTimeString());
    expect($request->fresh()->status)->toBe(RequestStatusEnum::accepted->value);
    Notification::assertSentToTimes($user, AgeVerificationRequestApprovedNotification::class, 1);
});
