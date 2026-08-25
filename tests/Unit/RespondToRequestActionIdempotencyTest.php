<?php

use App\Actions\Request\CreateRequestAction;
use App\Actions\Request\RespondToCounsellorVerificationRequestAction;
use App\Actions\Request\RespondToDiscussionRequestAction;
use App\Actions\Request\RespondToGuardianshipRequestAction;
use App\Actions\Request\RespondToTherapyAssistanceRequestAction;
use App\DTOs\CreateRequestDTO;
use App\DTOs\RequestResponseDTO;
use App\Enums\LicensingAuthorityTypeEnum;
use App\Enums\LicensingTypeEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\Guardianship;
use App\Models\License;
use App\Models\LicensingAuthority;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\GuardianshipEstablishedNotification;
use App\Notifications\TherapyAssistanceRequestAcceptedNotification;
use Illuminate\Support\Facades\Notification;

// SCRUM-91: extends the "no-op if already non-pending, decided under a lock" guard that
// SCRUM-80 added for RespondToGroupTherapyMembershipRequestAction to the other four
// RespondTo*RequestAction classes -- a duplicate/repeated respond call on an already-resolved
// request must not re-run side effects (creating a duplicate row, re-attaching, re-notifying).

test('responding to an already-accepted guardianship request a second time does not create a duplicate guardianship row or re-notify', function () {
    Notification::fake();

    $ward = User::factory()->create();
    $guardian = User::factory()->create(['dob' => now()->subYears(30), 'email_verified_at' => now()]);

    $request = CreateRequestAction::new()->execute(
        CreateRequestDTO::new()->fromArray([
            'from' => $ward,
            'to' => $guardian,
            'for' => $ward,
            'type' => RequestTypeEnum::guardianship->value,
        ])
    );

    $accepted = RespondToGuardianshipRequestAction::new()->execute(
        RequestResponseDTO::new()->fromArray([
            'user' => $guardian,
            'response' => 'accepted',
            'request' => $request,
        ])
    );

    expect($accepted->status)->toBe(RequestStatusEnum::accepted->value);
    expect(Guardianship::count())->toBe(1);
    Notification::assertSentToTimes($ward, GuardianshipEstablishedNotification::class, 1);

    $second = RespondToGuardianshipRequestAction::new()->execute(
        RequestResponseDTO::new()->fromArray([
            'user' => $guardian,
            'response' => 'accepted',
            'request' => $request->fresh(),
        ])
    );

    expect($second->status)->toBe(RequestStatusEnum::accepted->value);
    expect(Guardianship::count())->toBe(1);
    Notification::assertSentToTimes($ward, GuardianshipEstablishedNotification::class, 1);
});

test('responding to a discussion request already marked accepted does not re-attach the counsellor or re-notify', function () {
    Notification::fake();

    $therapyOwner = User::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $therapyOwner->id]);
    $discussion = Discussion::factory()->create([
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
        'addedby_id' => $therapyOwner->id,
        'addedby_type' => User::class,
    ]);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);

    $request = CreateRequestAction::new()->execute(
        CreateRequestDTO::new()->fromArray([
            'from' => $therapyOwner,
            'to' => $counsellor,
            'for' => $discussion,
            'type' => RequestTypeEnum::discussion->value,
        ])
    );

    // Simulates the narrow window the lock's re-check protects against: another response to
    // this exact request already committed its status update by the time this call's lock is
    // acquired, but (as in a genuine race) hasn't necessarily reached this call's earlier
    // EnsureNotAlreadyPartOfDiscussionAction check -- which only inspects the pivot, not status.
    $request->update(['status' => RequestStatusEnum::accepted->value]);

    $result = RespondToDiscussionRequestAction::new()->execute(
        RequestResponseDTO::new()->fromArray([
            'response' => 'accepted',
            'request' => $request->fresh(),
        ])
    );

    expect($result->status)->toBe(RequestStatusEnum::accepted->value);
    expect($discussion->fresh()->counsellors()->whereKey($counsellor->id)->exists())->toBeFalse();
    Notification::assertNothingSent();
});

test('responding to an already-accepted therapy assistance request a second time does not re-notify or re-alert the guardian', function () {
    Notification::fake();

    $client = User::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id]);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $respondingUser = User::factory()->create();

    $request = CreateRequestAction::new()->execute(
        CreateRequestDTO::new()->fromArray([
            'from' => $client,
            'to' => $counsellor,
            'for' => $therapy,
            'type' => RequestTypeEnum::therapy->value,
        ])
    );

    $accepted = RespondToTherapyAssistanceRequestAction::new()->execute(
        RequestResponseDTO::new()->fromArray([
            'user' => $respondingUser,
            'response' => 'accepted',
            'request' => $request,
        ])
    );

    expect($accepted->status)->toBe(RequestStatusEnum::accepted->value);
    expect($therapy->fresh()->status)->toBe(TherapyStatusEnum::in_session->value);
    Notification::assertSentToTimes($client, TherapyAssistanceRequestAcceptedNotification::class, 1);

    $second = RespondToTherapyAssistanceRequestAction::new()->execute(
        RequestResponseDTO::new()->fromArray([
            'user' => $respondingUser,
            'response' => 'accepted',
            'request' => $request->fresh(),
        ])
    );

    expect($second->status)->toBe(RequestStatusEnum::accepted->value);
    Notification::assertSentToTimes($client, TherapyAssistanceRequestAcceptedNotification::class, 1);
});

test('accepting one of two pending therapy assistance requests rejects the other instead of overwriting the counsellor assignment', function () {
    Notification::fake();

    // A therapy can have several pending assistance requests -- from different counsellors --
    // at once, so the therapy row (not just the request row) is the shared mutable resource:
    // two different pending requests for the same therapy being accepted near-simultaneously
    // could otherwise both see no counsellor assigned yet and both "win", losing one of the two
    // counsellor_id writes and notifying both requesters that their acceptance succeeded
    // (SCRUM-91). Running the two `execute()` calls sequentially (as this test does) isn't
    // enough on its own to reach that branch -- the first call's sibling-invalidation query
    // would already flip the second request to INCONSEQUENCIAL before the second call starts,
    // and the request-level guard alone would catch that. To isolate the therapy-lock fix
    // specifically, the second request's `for` (Therapy) relation is force-loaded while still
    // counsellor-less, and the first accept's outcome is applied directly (bypassing its own
    // sibling-invalidation query) -- reproducing exactly the race window where the second
    // request is still PENDING but the therapy already has a counsellor. The fix is only
    // proven if it re-reads the therapy fresh under a lock rather than trusting a stale,
    // cached relation or a stale PENDING read.
    $client = User::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id]);
    $firstCounsellorUser = User::factory()->create();
    $firstCounsellor = Counsellor::factory()->create(['user_id' => $firstCounsellorUser->id]);
    $secondCounsellorUser = User::factory()->create();
    $secondCounsellor = Counsellor::factory()->create(['user_id' => $secondCounsellorUser->id]);

    $firstRequest = CreateRequestAction::new()->execute(
        CreateRequestDTO::new()->fromArray([
            'from' => $client,
            'to' => $firstCounsellor,
            'for' => $therapy,
            'type' => RequestTypeEnum::therapy->value,
        ])
    );
    $secondRequest = CreateRequestAction::new()->execute(
        CreateRequestDTO::new()->fromArray([
            'from' => $client,
            'to' => $secondCounsellor,
            'for' => $therapy,
            'type' => RequestTypeEnum::therapy->value,
        ])
    );

    $secondResponseDTO = RequestResponseDTO::new()->fromArray([
        'user' => $secondCounsellorUser,
        'response' => 'accepted',
        'request' => $secondRequest,
    ]);
    $secondResponseDTO->request->for; // force-load the still counsellor-less Therapy relation.

    // Apply the first accept's outcome directly, without running its own sibling-invalidation
    // query, so the second request is still PENDING when it's responded to below -- the exact
    // race window the therapy lock closes.
    $firstRequest->update(['status' => RequestStatusEnum::accepted->value]);
    $therapy->update(['counsellor_id' => $firstCounsellor->id, 'status' => TherapyStatusEnum::in_session->value]);

    expect($therapy->fresh()->counsellor_id)->toBe($firstCounsellor->id);

    $secondResult = RespondToTherapyAssistanceRequestAction::new()->execute($secondResponseDTO);

    expect($secondResult->status)->toBe(RequestStatusEnum::rejected->value);
    expect($therapy->fresh()->counsellor_id)->toBe($firstCounsellor->id);
    Notification::assertNotSentTo($client, TherapyAssistanceRequestAcceptedNotification::class);
});

test('responding to an already-accepted counsellor verification request a second time does not re-verify or error', function () {
    $authority = LicensingAuthority::factory()->create([
        'name' => 'Test Authority',
        'type' => LicensingAuthorityTypeEnum::govermental->value,
        'license_type' => LicensingTypeEnum::number->value,
    ]);

    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);

    $nationalIdLicense = new License(['number' => '123']);
    $nationalIdLicense->licensingAuthority()->associate($authority);
    $nationalIdLicense->for()->associate($counsellor);
    $nationalIdLicense->save();

    $otherLicense = new License(['number' => '456']);
    $otherLicense->licensingAuthority()->associate($authority);
    $otherLicense->for()->associate($counsellor);
    $otherLicense->save();

    $admin = User::factory()->create();

    $request = CreateRequestAction::new()->execute(
        CreateRequestDTO::new()->fromArray([
            'from' => $counsellor,
            'to' => $admin,
            'for' => $counsellor,
            'type' => RequestTypeEnum::counsellor->value,
            'data' => [
                'nationalIdLicense' => $nationalIdLicense->id,
                'otherLicense' => $otherLicense->id,
            ],
        ])
    );

    $accepted = RespondToCounsellorVerificationRequestAction::new()->execute(
        RequestResponseDTO::new()->fromArray([
            'response' => 'accepted',
            'request' => $request,
        ])
    );

    expect($accepted->status)->toBe(RequestStatusEnum::accepted->value);
    expect($counsellor->fresh()->verified_at)->not->toBeNull();

    $firstVerifiedAt = $counsellor->fresh()->verified_at;

    $second = RespondToCounsellorVerificationRequestAction::new()->execute(
        RequestResponseDTO::new()->fromArray([
            'response' => 'accepted',
            'request' => $request->fresh(),
        ])
    );

    expect($second->status)->toBe(RequestStatusEnum::accepted->value);
    expect($counsellor->fresh()->verified_at)->toEqual($firstVerifiedAt);
});
