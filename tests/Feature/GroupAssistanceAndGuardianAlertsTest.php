<?php

use App\Actions\Request\EnsureUserCanRespondToRequestAction;
use App\Actions\Request\RespondToGroupTherapyAssistanceRequestAction;
use App\Enums\RequestTypeEnum;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Request as AppRequest;
use App\Models\User;
use App\Notifications\TherapyAssistanceRequestAcceptedNotification;
use Illuminate\Support\Facades\Notification;

// SCRUM-358: group invites can be accepted (the counsellor joins as active unless the group is
// full), and the "assistance accepted" guardian alert only ever goes to the client's guardians.

beforeEach(fn () => Notification::fake());

function aGroupInviteFor358(GroupTherapy $group, Counsellor $counsellor): AppRequest
{
    return AppRequest::query()->forceCreate([
        'from_type' => User::class, 'from_id' => $group->addedby_id,
        'to_type' => Counsellor::class, 'to_id' => $counsellor->id,
        'for_type' => GroupTherapy::class, 'for_id' => $group->id,
        'type' => RequestTypeEnum::groupTherapy->value, 'status' => 'PENDING', 'data' => [],
    ])->fresh();
}

function aCounsellorFor358(): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
}

test('a counsellor accepting a group invite joins the group as an active counsellor, and the creator is told', function () {
    $creator = User::factory()->adult()->create();
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $creator->id, 'max_counsellors' => 3]);
    $counsellor = aCounsellorFor358();
    $invite = aGroupInviteFor358($group, $counsellor);

    $this->actingAs($counsellor->user)->postJson(route('requests.respond', ['requestId' => $invite->id]), ['response' => 'accepted'])->assertSuccessful();

    expect($invite->fresh()->status)->toBe('ACCEPTED')
        ->and($group->fresh()->isCounsellor($counsellor))->toBeTrue()
        ->and($group->counsellors()->whereKey($counsellor->id)->first()->pivot->role)->toBe('NORMAL');
    Notification::assertSentTo($creator, TherapyAssistanceRequestAcceptedNotification::class);
});

test('a full group refuses the accept with a clear message and leaves the invite pending; filling it closes the rest', function () {
    $creator = User::factory()->adult()->create();
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $creator->id, 'max_counsellors' => 1]);
    [$first, $second, $third] = [aCounsellorFor358(), aCounsellorFor358(), aCounsellorFor358()];
    $firstInvite = aGroupInviteFor358($group, $first);
    $secondInvite = aGroupInviteFor358($group, $second);

    $this->actingAs($first->user)->postJson(route('requests.respond', ['requestId' => $firstInvite->id]), ['response' => 'accepted'])->assertSuccessful();
    // Filling the only place closed the other invite.
    expect($secondInvite->fresh()->status)->toBe('INCONSEQUENCIAL');

    // A new invite after the group is full is refused, and stays pending.
    $thirdInvite = aGroupInviteFor358($group, $third);
    $this->actingAs($third->user)->postJson(route('requests.respond', ['requestId' => $thirdInvite->id]), ['response' => 'accepted'])
        ->assertStatus(422)->assertJsonPath('error', RespondToGroupTherapyAssistanceRequestAction::GROUP_FULL);
    expect($thirdInvite->fresh()->status)->toBe('PENDING')
        ->and($group->fresh()->isCounsellor($third))->toBeFalse();
});

test('declining a group invite just declines it', function () {
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()->adult()->create()->id]);
    $counsellor = aCounsellorFor358();
    $invite = aGroupInviteFor358($group, $counsellor);

    $this->actingAs($counsellor->user)->postJson(route('requests.respond', ['requestId' => $invite->id]), ['response' => 'rejected'])->assertSuccessful();
    expect($invite->fresh()->status)->toBe('REJECTED')->and($group->fresh()->isCounsellor($counsellor))->toBeFalse();
});

// (The guardian-alert routing is covered by GuardianAlertWiringTest.)

test('only the invited, verified counsellor can accept -- not an admin for them, not the group\'s own client, not an ended group', function () {
    $creator = User::factory()->adult()->create();
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $creator->id, 'max_counsellors' => 5]);
    $accept = fn (User $as, AppRequest $invite) => $this->actingAs($as)->postJson(route('requests.respond', ['requestId' => $invite->id]), ['response' => 'accepted']);

    // An admin can't accept on the counsellor's behalf -- refused before the invite's own checks
    // (SCRUM-387: an admin answers only the admin queue's types).
    $counsellor = aCounsellorFor358();
    $admin = User::factory()->has(Administrator::factory())->create();
    $accept($admin, aGroupInviteFor358($group, $counsellor))->assertStatus(422)->assertJsonPath('error', EnsureUserCanRespondToRequestAction::NOT_ALLOWED);

    // The group's own client inviting their own counsellor profile would gain moderation powers.
    $ownProfile = Counsellor::factory()->create(['user_id' => $creator->id]);
    $accept($creator, aGroupInviteFor358($group, $ownProfile))->assertStatus(422)->assertJsonPath('error', RespondToGroupTherapyAssistanceRequestAction::NOT_ALLOWED);

    // Nor a member of the group.
    $memberProfile = aCounsellorFor358();
    $group->users()->attach($memberProfile->user_id, ['anonymous' => false]);
    $accept($memberProfile->user, aGroupInviteFor358($group, $memberProfile))->assertStatus(422);

    // Nor an unverified counsellor.
    $unverified = aCounsellorFor358();
    $unverified->forceFill(['verified_at' => null])->save();
    $accept($unverified->user, aGroupInviteFor358($group, $unverified))->assertStatus(422);

    // Nor into a group that has ended.
    $ended = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $creator->id, 'status' => 'ENDED']);
    $late = aCounsellorFor358();
    $accept($late->user, aGroupInviteFor358($ended, $late))->assertStatus(422);

    foreach ([$counsellor, $ownProfile, $memberProfile, $unverified] as $c) {
        expect($group->fresh()->isCounsellor($c))->toBeFalse();
    }
});

test('re-joining keeps a counsellor\'s existing role', function () {
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()->adult()->create()->id, 'max_counsellors' => 5]);
    $counsellor = aCounsellorFor358();
    $group->counsellors()->attach($counsellor->id, ['state' => 'INACTIVE', 'role' => 'COORDINATOR']);

    $this->actingAs($counsellor->user)->postJson(route('requests.respond', ['requestId' => aGroupInviteFor358($group, $counsellor)->id]), ['response' => 'accepted'])->assertSuccessful();

    $pivot = $group->counsellors()->whereKey($counsellor->id)->first()->pivot;
    expect($pivot->state)->toBe('ACTIVE')->and($pivot->role)->toBe('COORDINATOR');
});

test('the creator of a counsellor-made group is told, in group wording, who accepted', function () {
    $creatorCounsellor = aCounsellorFor358();
    $group = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $creatorCounsellor->id, 'max_counsellors' => 5]);
    $joining = aCounsellorFor358();
    // A fixed name: a random one with an apostrophe is encoded differently in the mail than by e().
    $joining->forceFill(['name' => 'Dr Joining Counsellor'])->save();
    $invite = AppRequest::query()->forceCreate([
        'from_type' => Counsellor::class, 'from_id' => $creatorCounsellor->id,
        'to_type' => Counsellor::class, 'to_id' => $joining->id,
        'for_type' => GroupTherapy::class, 'for_id' => $group->id,
        'type' => RequestTypeEnum::groupTherapy->value, 'status' => 'PENDING', 'data' => [],
    ])->fresh();

    $mail = (string) (new TherapyAssistanceRequestAcceptedNotification($invite))->toMail($creatorCounsellor)->render();
    expect($mail)->toContain('Your invitation to join the group therapy')
        ->toContain(e($joining->getName()))
        ->not->toContain('an anonymous user')
        ->toContain('group-therapies/'.$group->id);
});
