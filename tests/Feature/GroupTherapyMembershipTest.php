<?php

use App\Actions\GroupTherapy\JoinGroupTherapyAction;
use App\Actions\Request\RespondToGroupTherapyMembershipRequestAction;
use App\DTOs\JoinGroupTherapyDTO;
use App\DTOs\RequestResponseDTO;
use App\Enums\ConstantsEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Exceptions\CannotCreateTherapyException;
use App\Exceptions\CannotJoinGroupTherapyException;
use App\Http\Resources\RequestResource;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\Request as RequestModel;
use App\Models\User;
use App\Notifications\GroupTherapyMembershipRequestAcceptedGuardianNotification;
use App\Notifications\GroupTherapyMembershipRequestAcceptedNotification;
use App\Notifications\GroupTherapyMembershipRequestRejectedNotification;
use App\Notifications\GroupTherapyMembershipRequestSentNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

function anAdult(array $attributes = []): User
{
    return User::factory()->create(array_merge(['dob' => now()->subYears(25)], $attributes));
}

function aGroupTherapy(User $creator, array $attributes = []): GroupTherapy
{
    return GroupTherapy::factory()->create(array_merge([
        'addedby_type' => User::class,
        'addedby_id' => $creator->id,
        'anonymous' => false,
        'allow_anyone' => true,
        'max_users' => 5,
    ], $attributes));
}

test('immediate join (allow_anyone true) attaches the joiner with the requested anonymity value', function () {
    $creator = anAdult();
    $groupTherapy = aGroupTherapy($creator, ['allow_anyone' => true, 'anonymous' => false]);
    $joiner = anAdult();

    $result = JoinGroupTherapyAction::new()->execute(
        JoinGroupTherapyDTO::new()->fromArray([
            'user' => $joiner,
            'groupTherapy' => $groupTherapy,
            'anonymous' => true,
        ])
    );

    expect($result)->toBeInstanceOf(GroupTherapy::class);
    expect($groupTherapy->users()->whereKey($joiner->id)->exists())->toBeTrue();
    expect((bool) $groupTherapy->users()->whereKey($joiner->id)->first()->pivot->anonymous)->toBeTrue();
    expect($groupTherapy->fresh()->isParticipant($joiner))->toBeTrue();
});

test('a group-level anonymous flag forces the pivot anonymity to true at join-time regardless of what was requested', function () {
    $creator = anAdult();
    $groupTherapy = aGroupTherapy($creator, ['allow_anyone' => true, 'anonymous' => true]);
    $joiner = anAdult();

    JoinGroupTherapyAction::new()->execute(
        JoinGroupTherapyDTO::new()->fromArray([
            'user' => $joiner,
            'groupTherapy' => $groupTherapy,
            'anonymous' => false,
        ])
    );

    expect((bool) $groupTherapy->users()->whereKey($joiner->id)->first()->pivot->anonymous)->toBeTrue();
});

test('request-based join (allow_anyone false) creates a pending Request, not a pivot row', function () {
    Notification::fake();

    $creator = anAdult();
    $groupTherapy = aGroupTherapy($creator, ['allow_anyone' => false]);
    $joiner = anAdult();

    $result = JoinGroupTherapyAction::new()->execute(
        JoinGroupTherapyDTO::new()->fromArray([
            'user' => $joiner,
            'groupTherapy' => $groupTherapy,
            'anonymous' => true,
        ])
    );

    expect($result)->toBeInstanceOf(RequestModel::class);
    expect($result->type)->toBe(RequestTypeEnum::groupTherapyMembership->value);
    expect($result->status)->toBe(RequestStatusEnum::pending->value);
    expect($result->from_id)->toBe($joiner->id);
    expect($result->to_id)->toBe($creator->id);
    expect($result->data['anonymous'])->toBeTrue();
    expect($groupTherapy->users()->whereKey($joiner->id)->exists())->toBeFalse();

    Notification::assertSentTo($creator, GroupTherapyMembershipRequestSentNotification::class);
});

test('accepting a membership request attaches the requester with the anonymity value captured at request-time', function () {
    Notification::fake();

    $creator = anAdult();
    $groupTherapy = aGroupTherapy($creator, ['allow_anyone' => false, 'anonymous' => false]);
    $joiner = anAdult();

    $request = JoinGroupTherapyAction::new()->execute(
        JoinGroupTherapyDTO::new()->fromArray([
            'user' => $joiner,
            'groupTherapy' => $groupTherapy,
            'anonymous' => true,
        ])
    );

    $accepted = RespondToGroupTherapyMembershipRequestAction::new()->execute(
        RequestResponseDTO::new()->fromArray([
            'user' => $creator,
            'response' => 'accepted',
            'request' => $request,
        ])
    );

    expect($accepted->status)->toBe(RequestStatusEnum::accepted->value);
    expect($groupTherapy->users()->whereKey($joiner->id)->exists())->toBeTrue();
    expect((bool) $groupTherapy->users()->whereKey($joiner->id)->first()->pivot->anonymous)->toBeTrue();
    expect($groupTherapy->fresh()->isParticipant($joiner))->toBeTrue();

    Notification::assertSentTo($joiner, GroupTherapyMembershipRequestAcceptedNotification::class);
});

test('the group-level anonymous flag forces the pivot anonymity to true at accept-time regardless of what was requested', function () {
    Notification::fake();

    $creator = anAdult();
    $groupTherapy = aGroupTherapy($creator, ['allow_anyone' => false, 'anonymous' => true]);
    $joiner = anAdult();

    $request = JoinGroupTherapyAction::new()->execute(
        JoinGroupTherapyDTO::new()->fromArray([
            'user' => $joiner,
            'groupTherapy' => $groupTherapy,
            'anonymous' => false,
        ])
    );

    RespondToGroupTherapyMembershipRequestAction::new()->execute(
        RequestResponseDTO::new()->fromArray([
            'user' => $creator,
            'response' => 'accepted',
            'request' => $request,
        ])
    );

    expect((bool) $groupTherapy->users()->whereKey($joiner->id)->first()->pivot->anonymous)->toBeTrue();
});

test('rejecting a membership request does not attach the requester', function () {
    Notification::fake();

    $creator = anAdult();
    $groupTherapy = aGroupTherapy($creator, ['allow_anyone' => false]);
    $joiner = anAdult();

    $request = JoinGroupTherapyAction::new()->execute(
        JoinGroupTherapyDTO::new()->fromArray([
            'user' => $joiner,
            'groupTherapy' => $groupTherapy,
        ])
    );

    $rejected = RespondToGroupTherapyMembershipRequestAction::new()->execute(
        RequestResponseDTO::new()->fromArray([
            'user' => $creator,
            'response' => 'rejected',
            'request' => $request,
        ])
    );

    expect($rejected->status)->toBe(RequestStatusEnum::rejected->value);
    expect($groupTherapy->users()->whereKey($joiner->id)->exists())->toBeFalse();

    Notification::assertSentTo($joiner, GroupTherapyMembershipRequestRejectedNotification::class);
});

test('joining is rejected once the group is at capacity (allow_anyone true)', function () {
    $creator = anAdult();
    $groupTherapy = aGroupTherapy($creator, ['allow_anyone' => true, 'max_users' => 1]);
    $groupTherapy->users()->attach(anAdult()->id, ['anonymous' => false]);

    $joiner = anAdult();

    JoinGroupTherapyAction::new()->execute(
        JoinGroupTherapyDTO::new()->fromArray([
            'user' => $joiner,
            'groupTherapy' => $groupTherapy,
        ])
    );
})->throws(CannotJoinGroupTherapyException::class);

test('joining is rejected once the group is at capacity (allow_anyone false)', function () {
    $creator = anAdult();
    $groupTherapy = aGroupTherapy($creator, ['allow_anyone' => false, 'max_users' => 1]);
    $groupTherapy->users()->attach(anAdult()->id, ['anonymous' => false]);

    $joiner = anAdult();

    JoinGroupTherapyAction::new()->execute(
        JoinGroupTherapyDTO::new()->fromArray([
            'user' => $joiner,
            'groupTherapy' => $groupTherapy,
        ])
    );
})->throws(CannotJoinGroupTherapyException::class);

test('a membership request is rejected at accept-time when the group filled up in the meantime', function () {
    Notification::fake();

    $creator = anAdult();
    $groupTherapy = aGroupTherapy($creator, ['allow_anyone' => false, 'max_users' => 1]);
    $joiner = anAdult();

    $request = JoinGroupTherapyAction::new()->execute(
        JoinGroupTherapyDTO::new()->fromArray([
            'user' => $joiner,
            'groupTherapy' => $groupTherapy,
        ])
    );

    // The group fills up (via someone else) between the request being sent and it being
    // responded to.
    $groupTherapy->users()->attach(anAdult()->id, ['anonymous' => false]);

    $responded = RespondToGroupTherapyMembershipRequestAction::new()->execute(
        RequestResponseDTO::new()->fromArray([
            'user' => $creator,
            'response' => 'accepted',
            'request' => $request,
        ])
    );

    expect($responded->status)->toBe(RequestStatusEnum::rejected->value);
    expect($groupTherapy->users()->whereKey($joiner->id)->exists())->toBeFalse();

    Notification::assertSentTo($joiner, GroupTherapyMembershipRequestRejectedNotification::class);
});

test('joining is rejected when the user already has a pending membership request for the group', function () {
    Notification::fake();

    $creator = anAdult();
    $groupTherapy = aGroupTherapy($creator, ['allow_anyone' => false]);
    $joiner = anAdult();

    JoinGroupTherapyAction::new()->execute(
        JoinGroupTherapyDTO::new()->fromArray([
            'user' => $joiner,
            'groupTherapy' => $groupTherapy,
        ])
    );

    JoinGroupTherapyAction::new()->execute(
        JoinGroupTherapyDTO::new()->fromArray([
            'user' => $joiner,
            'groupTherapy' => $groupTherapy,
        ])
    );
})->throws(CannotJoinGroupTherapyException::class);

// Regression test for a real bug found during review: User::hasPendingRequestFor()'s
// `->whereTo($this)->orWhereFrom($this)` was ungrouped, so `orWhereFrom` became a top-level OR
// unscoped from `wherePending()`/`whereFor($model)` -- meaning a user with ANY prior request
// (any status, any target) was incorrectly reported as having a pending request for every OTHER
// group therapy too, permanently blocking them from joining anything.
test('a user\'s unrelated, already-accepted request for a different group does not block joining a new group', function () {
    $creator = anAdult();
    $otherGroupTherapy = aGroupTherapy($creator, ['allow_anyone' => false]);
    $joiner = anAdult();

    $oldRequest = JoinGroupTherapyAction::new()->execute(
        JoinGroupTherapyDTO::new()->fromArray([
            'user' => $joiner,
            'groupTherapy' => $otherGroupTherapy,
        ])
    );
    RespondToGroupTherapyMembershipRequestAction::new()->execute(
        RequestResponseDTO::new()->fromArray([
            'user' => $creator,
            'response' => 'accepted',
            'request' => $oldRequest,
        ])
    );

    $newGroupTherapy = aGroupTherapy($creator, ['allow_anyone' => true]);

    $result = JoinGroupTherapyAction::new()->execute(
        JoinGroupTherapyDTO::new()->fromArray([
            'user' => $joiner,
            'groupTherapy' => $newGroupTherapy,
        ])
    );

    expect($result)->toBeInstanceOf(GroupTherapy::class);
    expect($newGroupTherapy->users()->whereKey($joiner->id)->exists())->toBeTrue();
});

test('joining is rejected when the user is already a participant', function () {
    $creator = anAdult();
    $groupTherapy = aGroupTherapy($creator, ['allow_anyone' => true]);

    JoinGroupTherapyAction::new()->execute(
        JoinGroupTherapyDTO::new()->fromArray([
            'user' => $creator,
            'groupTherapy' => $groupTherapy,
        ])
    );
})->throws(CannotJoinGroupTherapyException::class);

test('a minor without a guardian cannot join a group therapy', function () {
    $creator = anAdult();
    $groupTherapy = aGroupTherapy($creator, ['allow_anyone' => true]);

    $minor = User::factory()->create(['dob' => now()->subYears(15)]);

    JoinGroupTherapyAction::new()->execute(
        JoinGroupTherapyDTO::new()->fromArray([
            'user' => $minor,
            'groupTherapy' => $groupTherapy,
        ])
    );
})->throws(CannotCreateTherapyException::class);

test('a minor with a guardian can request to join, and accepting it alerts the guardian', function () {
    Notification::fake();

    $creator = anAdult();
    $groupTherapy = aGroupTherapy($creator, ['allow_anyone' => false]);

    $minor = User::factory()->create(['dob' => now()->subYears(15)]);
    $guardian = anAdult();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minor->id]);

    $request = JoinGroupTherapyAction::new()->execute(
        JoinGroupTherapyDTO::new()->fromArray([
            'user' => $minor,
            'groupTherapy' => $groupTherapy,
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

    expect($groupTherapy->users()->whereKey($minor->id)->exists())->toBeTrue();

    Notification::assertSentTo($guardian, GroupTherapyMembershipRequestAcceptedGuardianNotification::class);
});

// HTTP-level coverage for the Controller -> Service -> Action -> DTO wiring.

test('POST api.group.therapies.join immediately attaches an adult user when allow_anyone is true', function () {
    $creator = anAdult();
    $groupTherapy = aGroupTherapy($creator, ['allow_anyone' => true, 'anonymous' => false]);
    $joiner = anAdult();

    $response = $this
        ->actingAs($joiner)
        ->postJson(route('api.group.therapies.join', ['groupTherapyId' => $groupTherapy->id]), [
            'anonymous' => false,
        ]);

    $response->assertOk();
    expect($groupTherapy->users()->whereKey($joiner->id)->exists())->toBeTrue();
});

// SCRUM-126: `joinGroupTherapy` used `(bool) $request->anonymous` on a plain Request with no
// 'boolean' validation rule at all, so a string "false" silently flipped to PHP true -- a live
// bug, not just a defense-in-depth cleanup.
test('POST api.group.therapies.join with a string "false" anonymous value correctly persists it as false', function () {
    $creator = anAdult();
    $groupTherapy = aGroupTherapy($creator, ['allow_anyone' => true, 'anonymous' => false]);
    $joiner = anAdult();

    $response = $this
        ->actingAs($joiner)
        ->postJson(route('api.group.therapies.join', ['groupTherapyId' => $groupTherapy->id]), [
            'anonymous' => 'false',
        ]);

    $response->assertOk();
    expect((bool) $groupTherapy->users()->whereKey($joiner->id)->first()->pivot->anonymous)->toBeFalse();
});

test('POST api.group.therapies.join creates a pending request when allow_anyone is false', function () {
    Notification::fake();

    $creator = anAdult();
    $groupTherapy = aGroupTherapy($creator, ['allow_anyone' => false]);
    $joiner = anAdult();

    $response = $this
        ->actingAs($joiner)
        ->postJson(route('api.group.therapies.join', ['groupTherapyId' => $groupTherapy->id]), [
            'anonymous' => false,
        ]);

    $response->assertOk();
    expect($groupTherapy->users()->whereKey($joiner->id)->exists())->toBeFalse();
    expect(RequestModel::query()->whereType(RequestTypeEnum::groupTherapyMembership->value)->wherePending()->count())->toBe(1);
});

test('POST api.group.therapies.join surfaces a full group as 422 with a real message instead of a silent empty 200 (SCRUM-94)', function () {
    // Before SCRUM-94, GroupTherapyController::joinGroupTherapy's catch block called
    // $this->returnFailure($request, $th) without `return`, so the method fell through and
    // implicitly returned null -- Laravel serialised that as an empty HTTP 200, silently
    // hiding the real 422 CannotJoinGroupTherapyException from the client.
    $creator = anAdult();
    $groupTherapy = aGroupTherapy($creator, ['allow_anyone' => true, 'max_users' => 1]);
    $groupTherapy->users()->attach(anAdult()->id, ['anonymous' => false]);

    $joiner = anAdult();

    $response = $this
        ->actingAs($joiner)
        ->postJson(route('api.group.therapies.join', ['groupTherapyId' => $groupTherapy->id]), [
            'anonymous' => false,
        ]);

    $response->assertStatus(422);
    expect($response->json('message'))->toBe('This group therapy has reached its maximum number of members.');
    expect($groupTherapy->users()->whereKey($joiner->id)->exists())->toBeFalse();
});

test('POST requests.respond accepts a pending membership request over HTTP', function () {
    Notification::fake();

    $creator = anAdult();
    $groupTherapy = aGroupTherapy($creator, ['allow_anyone' => false]);
    $joiner = anAdult();

    $request = JoinGroupTherapyAction::new()->execute(
        JoinGroupTherapyDTO::new()->fromArray([
            'user' => $joiner,
            'groupTherapy' => $groupTherapy,
        ])
    );

    $response = $this
        ->actingAs($creator)
        ->postJson(route('requests.respond', ['requestId' => $request->id]), [
            'response' => 'accepted',
        ]);

    $response->assertCreated();
    expect($groupTherapy->users()->whereKey($joiner->id)->exists())->toBeTrue();
});

test('POST requests.respond rejects a garbage response value instead of writing it straight to status (SCRUM-89)', function () {
    $creator = anAdult();
    $groupTherapy = aGroupTherapy($creator, ['allow_anyone' => false]);
    $joiner = anAdult();

    $request = JoinGroupTherapyAction::new()->execute(
        JoinGroupTherapyDTO::new()->fromArray([
            'user' => $joiner,
            'groupTherapy' => $groupTherapy,
        ])
    );

    $response = $this
        ->actingAs($creator)
        ->postJson(route('requests.respond', ['requestId' => $request->id]), [
            'response' => 'MAYBE',
        ]);

    // 422, not the pre-SCRUM-90 hardcoded 500 -- BadRequestException carries its own code.
    $response->assertStatus(422);
    expect($request->fresh()->status)->toBe(RequestStatusEnum::pending->value);
    expect($groupTherapy->users()->whereKey($joiner->id)->exists())->toBeFalse();
});

// SCRUM-171: an already-decided request's status was never actually at risk (each
// RespondTo*RequestAction already re-checks it under a lock and no-ops -- SCRUM-80/91), but the
// generic respond pipeline still reported that no-op as a misleading 201 success rather than
// telling the caller their response did nothing. This pins the deliberate behavior change: a
// clean 422 instead.
test('POST requests.respond rejects a response to an already-accepted request', function () {
    $creator = anAdult();
    $groupTherapy = aGroupTherapy($creator, ['allow_anyone' => false]);
    $joiner = anAdult();

    $request = JoinGroupTherapyAction::new()->execute(
        JoinGroupTherapyDTO::new()->fromArray([
            'user' => $joiner,
            'groupTherapy' => $groupTherapy,
        ])
    );
    $request->update(['status' => RequestStatusEnum::accepted->value]);

    $response = $this
        ->actingAs($creator)
        ->postJson(route('requests.respond', ['requestId' => $request->id]), [
            'response' => 'rejected',
        ]);

    $response->assertStatus(422);
    expect($response->json('error'))->toBe('This request is no longer pending and can no longer be responded to.');
    expect($request->fresh()->status)->toBe(RequestStatusEnum::accepted->value);
});

test('POST requests.respond rejects a response to an already-rejected request', function () {
    $creator = anAdult();
    $groupTherapy = aGroupTherapy($creator, ['allow_anyone' => false]);
    $joiner = anAdult();

    $request = JoinGroupTherapyAction::new()->execute(
        JoinGroupTherapyDTO::new()->fromArray([
            'user' => $joiner,
            'groupTherapy' => $groupTherapy,
        ])
    );
    $request->update(['status' => RequestStatusEnum::rejected->value]);

    $response = $this
        ->actingAs($creator)
        ->postJson(route('requests.respond', ['requestId' => $request->id]), [
            'response' => 'accepted',
        ]);

    $response->assertStatus(422);
    expect($request->fresh()->status)->toBe(RequestStatusEnum::rejected->value);
    expect($groupTherapy->users()->whereKey($joiner->id)->exists())->toBeFalse();
});

// Regression tests: RequestResource::toArray() previously rendered `from` via an unmasked
// UserMiniResource() unconditionally, for every request type -- for a group-therapy membership
// request specifically, this leaked the requester's real identity to the group creator (and
// anyone else who could see the request) even when the requester chose anonymity or the group
// itself is anonymous, defeating the exact protection this ticket's feature is meant to provide.

function renderRequestResourceFrom(RequestModel $request, User $viewer): array
{
    // toArray() can return a nested JsonResource object (the non-anonymous branch) rather than
    // a plain array -- json round-tripping forces it to fully resolve, matching what a real
    // HTTP response would actually serialize.
    $rendered = (new RequestResource($request))->toArray(
        Request::create('/')->setUserResolver(fn () => $viewer)
    );

    return json_decode(json_encode($rendered), true)['from'];
}

test('RequestResource masks the requester\'s identity when the group-therapy membership request is anonymous', function () {
    $creator = anAdult();
    $groupTherapy = aGroupTherapy($creator, ['allow_anyone' => false, 'anonymous' => false]);
    $joiner = anAdult();

    $request = JoinGroupTherapyAction::new()->execute(
        JoinGroupTherapyDTO::new()->fromArray([
            'user' => $joiner,
            'groupTherapy' => $groupTherapy,
            'anonymous' => true,
        ])
    );

    $from = renderRequestResourceFrom($request, $creator);

    expect($from['fullName'])->toBe(ConstantsEnum::anonymousUserLabel->value);
    expect($from)->not->toHaveKey('username');
});

test('RequestResource masks the requester\'s identity for any viewer when the group itself is anonymous, even if the requester didn\'t request anonymity', function () {
    $creator = anAdult();
    $groupTherapy = aGroupTherapy($creator, ['allow_anyone' => false, 'anonymous' => true]);
    $joiner = anAdult();

    $request = JoinGroupTherapyAction::new()->execute(
        JoinGroupTherapyDTO::new()->fromArray([
            'user' => $joiner,
            'groupTherapy' => $groupTherapy,
            'anonymous' => false,
        ])
    );

    $from = renderRequestResourceFrom($request, $creator);

    expect($from['fullName'])->toBe(ConstantsEnum::anonymousUserLabel->value);
});

test('RequestResource does not mask the requester\'s own view of their own anonymous request', function () {
    $creator = anAdult();
    $groupTherapy = aGroupTherapy($creator, ['allow_anyone' => false]);
    $joiner = anAdult();

    $request = JoinGroupTherapyAction::new()->execute(
        JoinGroupTherapyDTO::new()->fromArray([
            'user' => $joiner,
            'groupTherapy' => $groupTherapy,
            'anonymous' => true,
        ])
    );

    $from = renderRequestResourceFrom($request, $joiner);

    expect($from['fullName'])->toBe($joiner->name);
});

test('RequestResource does not mask the requester\'s identity when the request is not anonymous', function () {
    $creator = anAdult();
    $groupTherapy = aGroupTherapy($creator, ['allow_anyone' => false, 'anonymous' => false]);
    $joiner = anAdult();

    $request = JoinGroupTherapyAction::new()->execute(
        JoinGroupTherapyDTO::new()->fromArray([
            'user' => $joiner,
            'groupTherapy' => $groupTherapy,
            'anonymous' => false,
        ])
    );

    $from = renderRequestResourceFrom($request, $creator);

    expect($from['fullName'])->toBe($joiner->name);
});

// Regression tests: RequestResource::toArray()'s `to` field (the group creator, for a
// group-therapy membership request) was never masked at all -- when the group itself is
// anonymous, the creator's real identity leaked via the requester's own pending-request view
// (GroupTherapyController::getGroupTherapy's pendingMembershipRequest prop), mirroring the same
// leak already fixed for `from` above, just in the other direction.

function renderRequestResourceTo(RequestModel $request, User $viewer): array
{
    $rendered = (new RequestResource($request))->toArray(
        Request::create('/')->setUserResolver(fn () => $viewer)
    );

    return json_decode(json_encode($rendered), true)['to'];
}

test('RequestResource masks the creator\'s identity to the requester when the group is anonymous', function () {
    $creator = anAdult();
    $groupTherapy = aGroupTherapy($creator, ['allow_anyone' => false, 'anonymous' => true]);
    $joiner = anAdult();

    $request = JoinGroupTherapyAction::new()->execute(
        JoinGroupTherapyDTO::new()->fromArray([
            'user' => $joiner,
            'groupTherapy' => $groupTherapy,
        ])
    );

    $to = renderRequestResourceTo($request, $joiner);

    expect($to['fullName'])->toBe(ConstantsEnum::anonymousUserLabel->value);
    expect($to)->not->toHaveKey('username');
});

test('RequestResource does not mask the creator\'s own view of their own group\'s request', function () {
    $creator = anAdult();
    $groupTherapy = aGroupTherapy($creator, ['allow_anyone' => false, 'anonymous' => true]);
    $joiner = anAdult();

    $request = JoinGroupTherapyAction::new()->execute(
        JoinGroupTherapyDTO::new()->fromArray([
            'user' => $joiner,
            'groupTherapy' => $groupTherapy,
        ])
    );

    $to = renderRequestResourceTo($request, $creator);

    expect($to['fullName'])->toBe($creator->name);
});

test('RequestResource does not mask the creator\'s identity when the group is not anonymous', function () {
    $creator = anAdult();
    $groupTherapy = aGroupTherapy($creator, ['allow_anyone' => false, 'anonymous' => false]);
    $joiner = anAdult();

    $request = JoinGroupTherapyAction::new()->execute(
        JoinGroupTherapyDTO::new()->fromArray([
            'user' => $joiner,
            'groupTherapy' => $groupTherapy,
        ])
    );

    $to = renderRequestResourceTo($request, $joiner);

    expect($to['fullName'])->toBe($creator->name);
});

// SCRUM-80: group_therapy_user has no unique constraint on (group_therapy_id, user_id), and
// neither JoinGroupTherapyAction nor RespondToGroupTherapyMembershipRequestAction locked the
// group row before checking capacity and attaching -- two concurrent joins/accepts against a
// near-full group could both pass the count check before either attached, and a
// double-submitted accept click could attach the same member twice.

test('the DB enforces a unique (group_therapy_id, user_id) pair on group_therapy_user', function () {
    $creator = anAdult();
    $groupTherapy = aGroupTherapy($creator);
    $member = anAdult();

    DB::table('group_therapy_user')->insert([
        'group_therapy_id' => $groupTherapy->id,
        'user_id' => $member->id,
        'anonymous' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('group_therapy_user')->insert([
        'group_therapy_id' => $groupTherapy->id,
        'user_id' => $member->id,
        'anonymous' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('accepting a membership request a second time (a duplicate submission) does not attach the member twice or re-notify', function () {
    Notification::fake();

    $creator = anAdult();
    $groupTherapy = aGroupTherapy($creator, ['allow_anyone' => false, 'max_users' => 5]);
    $joiner = anAdult();

    $request = JoinGroupTherapyAction::new()->execute(
        JoinGroupTherapyDTO::new()->fromArray([
            'user' => $joiner,
            'groupTherapy' => $groupTherapy,
        ])
    );

    $accepted = RespondToGroupTherapyMembershipRequestAction::new()->execute(
        RequestResponseDTO::new()->fromArray(['request' => $request, 'response' => 'accepted'])
    );
    expect($accepted->status)->toBe(RequestStatusEnum::accepted->value);
    expect($groupTherapy->users()->count())->toBe(1);

    // The exact same request, already ACCEPTED, responded to again -- must be a no-op rather
    // than attempting to attach $joiner a second time (which would violate the unique
    // constraint) or send a second acceptance notification.
    $secondResponse = RespondToGroupTherapyMembershipRequestAction::new()->execute(
        RequestResponseDTO::new()->fromArray(['request' => $accepted->fresh(), 'response' => 'accepted'])
    );

    expect($secondResponse->status)->toBe(RequestStatusEnum::accepted->value);
    expect($groupTherapy->users()->count())->toBe(1);
    Notification::assertSentTimes(GroupTherapyMembershipRequestAcceptedNotification::class, 1);
});

test('the unique-index migration deletes pre-existing duplicate group_therapy_user rows before adding the constraint', function () {
    $migrationPath = 'database/migrations/2026_08_24_000000_add_unique_index_to_group_therapy_user_table.php';

    Artisan::call('migrate:rollback', ['--path' => $migrationPath]);

    $creator = anAdult();
    $groupTherapy = aGroupTherapy($creator);
    $member = anAdult();

    // Simulates what the pre-SCRUM-80 race could have already produced in a real database --
    // two rows for the same (group_therapy_id, user_id) pair, the older one being the
    // legitimate membership.
    $olderRowId = DB::table('group_therapy_user')->insertGetId([
        'group_therapy_id' => $groupTherapy->id,
        'user_id' => $member->id,
        'anonymous' => false,
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ]);
    DB::table('group_therapy_user')->insert([
        'group_therapy_id' => $groupTherapy->id,
        'user_id' => $member->id,
        'anonymous' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('group_therapy_user')->where('group_therapy_id', $groupTherapy->id)->where('user_id', $member->id)->count())->toBe(2);

    Artisan::call('migrate', ['--path' => $migrationPath]);

    $remaining = DB::table('group_therapy_user')->where('group_therapy_id', $groupTherapy->id)->where('user_id', $member->id)->get();
    expect($remaining)->toHaveCount(1);
    expect($remaining->first()->id)->toBe($olderRowId);
});

test('POST requests.respond surfaces an unauthorized responder as 422, not a hardcoded 500 (SCRUM-90)', function () {
    $creator = anAdult();
    $groupTherapy = aGroupTherapy($creator, ['allow_anyone' => false]);
    $joiner = anAdult();
    $unrelatedUser = anAdult();

    $request = JoinGroupTherapyAction::new()->execute(
        JoinGroupTherapyDTO::new()->fromArray([
            'user' => $joiner,
            'groupTherapy' => $groupTherapy,
        ])
    );

    $response = $this
        ->actingAs($unrelatedUser)
        ->postJson(route('requests.respond', ['requestId' => $request->id]), [
            'response' => 'accepted',
        ]);

    $response->assertStatus(422);
    $response->assertJson(['status' => false]);
    expect($request->fresh()->status)->toBe(RequestStatusEnum::pending->value);
});
