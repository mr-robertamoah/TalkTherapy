<?php

use App\Actions\Request\CreateRequestAction;
use App\Actions\Request\RespondToDobChangeRequestAction;
use App\DTOs\CreateRequestDTO;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\Request;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\DobChangeRequestApprovedNotification;
use App\Notifications\DobChangeRequestRejectedNotification;
use Illuminate\Support\Facades\Notification;

// TT-4.10d/SCRUM-293: on approval, writes the new dob AND retroactively corrects the "was this
// person a minor" snapshot on every currently-existing Guardianship/Therapy/GroupTherapy record
// -- an approved change is a correction of the truth, not a prospective-only change (deliberately
// the opposite of TT-3.1e-a's video-consent-mode switch). On rejection, dob is left untouched.

function aDobChangeRequest(User $target, User $requester, ?User $to, string $newDob): Request
{
    return CreateRequestAction::new()->execute(
        CreateRequestDTO::new()->fromArray([
            'from' => $requester,
            'to' => $to,
            'for' => $target,
            'type' => RequestTypeEnum::dobChange->value,
            'data' => ['newDob' => $newDob, 'priorDob' => $target->dob?->toDateString()],
        ])
    );
}

test('approving writes the new dob to the target user', function () {
    $minor = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minor->id]);
    $newDob = now()->subYears(30)->toDateString();
    $request = aDobChangeRequest($minor, $minor, $guardian, $newDob);

    RespondToDobChangeRequestAction::new()->execute(RequestResponseDTO::new()->fromArray([
        'user' => $guardian,
        'response' => 'accepted',
        'request' => $request,
    ]));

    expect($minor->fresh()->dob->toDateString())->toBe($newDob);
});

test('approving retroactively corrects the ward_was_minor_at_creation snapshot on an existing Guardianship row', function () {
    $minor = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);
    $guardian = User::factory()->create();
    $guardianship = Guardianship::query()->create([
        'guardian_id' => $guardian->id, 'ward_id' => $minor->id, 'ward_was_minor_at_creation' => true,
    ]);
    $newDob = now()->subYears(30)->toDateString();
    $request = aDobChangeRequest($minor, $minor, $guardian, $newDob);

    RespondToDobChangeRequestAction::new()->execute(RequestResponseDTO::new()->fromArray([
        'user' => $guardian,
        'response' => 'accepted',
        'request' => $request,
    ]));

    expect($guardianship->fresh()->ward_was_minor_at_creation)->toBeFalse();
});

test('approving retroactively corrects the client_was_minor_at_creation snapshot on an existing Therapy record', function () {
    $minor = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minor->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $minor->id, 'client_was_minor_at_creation' => true,
    ]);
    $newDob = now()->subYears(30)->toDateString();
    $request = aDobChangeRequest($minor, $minor, $guardian, $newDob);

    RespondToDobChangeRequestAction::new()->execute(RequestResponseDTO::new()->fromArray([
        'user' => $guardian,
        'response' => 'accepted',
        'request' => $request,
    ]));

    expect($therapy->fresh()->client_was_minor_at_creation)->toBeFalse();
});

test('approving retroactively corrects the client_was_minor_at_creation snapshot on an existing GroupTherapy record', function () {
    $minor = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minor->id]);
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $minor->id, 'client_was_minor_at_creation' => true,
    ]);
    $newDob = now()->subYears(30)->toDateString();
    $request = aDobChangeRequest($minor, $minor, $guardian, $newDob);

    RespondToDobChangeRequestAction::new()->execute(RequestResponseDTO::new()->fromArray([
        'user' => $guardian,
        'response' => 'accepted',
        'request' => $request,
    ]));

    expect($groupTherapy->fresh()->client_was_minor_at_creation)->toBeFalse();
});

test('approving an adult-to-minor correction also flips the snapshot back to true', function () {
    $adult = User::factory()->create(['dob' => now()->subYears(30)->toDateString()]);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $adult->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $adult->id, 'client_was_minor_at_creation' => false,
    ]);
    $newDob = now()->subYears(15)->toDateString();
    $request = aDobChangeRequest($adult, $adult, $guardian, $newDob);

    RespondToDobChangeRequestAction::new()->execute(RequestResponseDTO::new()->fromArray([
        'user' => $guardian,
        'response' => 'accepted',
        'request' => $request,
    ]));

    expect($therapy->fresh()->client_was_minor_at_creation)->toBeTrue();
    expect($adult->fresh()->dob->toDateString())->toBe($newDob);
});

test('rejecting leaves dob and every snapshot untouched', function () {
    $minor = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minor->id, 'ward_was_minor_at_creation' => true]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $minor->id, 'client_was_minor_at_creation' => true,
    ]);
    $originalDob = $minor->dob->toDateString();
    $request = aDobChangeRequest($minor, $minor, $guardian, now()->subYears(30)->toDateString());

    RespondToDobChangeRequestAction::new()->execute(RequestResponseDTO::new()->fromArray([
        'user' => $guardian,
        'response' => null,
        'request' => $request,
    ]));

    expect($minor->fresh()->dob->toDateString())->toBe($originalDob);
    expect($therapy->fresh()->client_was_minor_at_creation)->toBeTrue();
    expect($request->fresh()->status)->toBe(RequestStatusEnum::rejected->value);
});

test('approving notifies the requester of the outcome', function () {
    Notification::fake();
    $minor = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minor->id]);
    $request = aDobChangeRequest($minor, $minor, $guardian, now()->subYears(30)->toDateString());

    RespondToDobChangeRequestAction::new()->execute(RequestResponseDTO::new()->fromArray([
        'user' => $guardian,
        'response' => 'accepted',
        'request' => $request,
    ]));

    Notification::assertSentTo($minor, DobChangeRequestApprovedNotification::class);
});

test('rejecting notifies the requester of the outcome', function () {
    Notification::fake();
    $minor = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minor->id]);
    $request = aDobChangeRequest($minor, $minor, $guardian, now()->subYears(30)->toDateString());

    RespondToDobChangeRequestAction::new()->execute(RequestResponseDTO::new()->fromArray([
        'user' => $guardian,
        'response' => null,
        'request' => $request,
    ]));

    Notification::assertSentTo($minor, DobChangeRequestRejectedNotification::class);
});

test('responding to an already-decided request a second time does not re-apply the dob change', function () {
    $minor = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minor->id]);
    $request = aDobChangeRequest($minor, $minor, $guardian, now()->subYears(30)->toDateString());

    RespondToDobChangeRequestAction::new()->execute(RequestResponseDTO::new()->fromArray([
        'user' => $guardian, 'response' => 'accepted', 'request' => $request,
    ]));
    $dobAfterFirstResponse = $minor->fresh()->dob->toDateString();

    // A second, different admin somehow responds again (e.g. a race, or a stale UI) --
    // the already-ACCEPTED status means this must no-op, not overwrite dob a second time.
    RespondToDobChangeRequestAction::new()->execute(RequestResponseDTO::new()->fromArray([
        'user' => $guardian, 'response' => 'rejected', 'request' => $request->fresh(),
    ]));

    expect($minor->fresh()->dob->toDateString())->toBe($dobAfterFirstResponse);
    expect($request->fresh()->status)->toBe(RequestStatusEnum::accepted->value);
});

test('responding to an already-decided request a second time does not re-notify the requester', function () {
    Notification::fake();
    $minor = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minor->id]);
    $request = aDobChangeRequest($minor, $minor, $guardian, now()->subYears(30)->toDateString());

    RespondToDobChangeRequestAction::new()->execute(RequestResponseDTO::new()->fromArray([
        'user' => $guardian, 'response' => 'accepted', 'request' => $request,
    ]));
    RespondToDobChangeRequestAction::new()->execute(RequestResponseDTO::new()->fromArray([
        'user' => $guardian, 'response' => 'accepted', 'request' => $request->fresh(),
    ]));

    Notification::assertSentToTimes($minor, DobChangeRequestApprovedNotification::class, 1);
});
