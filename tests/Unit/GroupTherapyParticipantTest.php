<?php

use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\User;

test('a group therapy added by a user recognises that user as a participant', function () {
    $addedbyUser = User::factory()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $addedbyUser->id,
    ]);

    expect($groupTherapy->isUser($addedbyUser))->toBeTrue();
    expect($groupTherapy->isParticipant($addedbyUser))->toBeTrue();
    expect($groupTherapy->isNotParticipant($addedbyUser))->toBeFalse();
});

test('a group therapy added by a counsellor recognises that counsellor as a participant, not as a user', function () {
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => Counsellor::class,
        'addedby_id' => $counsellor->id,
    ]);

    expect($groupTherapy->isCounsellor($counsellor))->toBeTrue();
    expect($groupTherapy->isParticipant($counsellorUser))->toBeTrue();
    // isUser() means "is this User the addedby" specifically -- a Counsellor addedby must not
    // also satisfy it, even though the counsellor's linked user is a participant via isCounsellor().
    expect($groupTherapy->isUser($counsellorUser))->toBeFalse();
});

test('an assigned counsellor (not the addedby) is recognised as a participant', function () {
    $addedbyUser = User::factory()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $addedbyUser->id,
    ]);

    $assignedCounsellorUser = User::factory()->create();
    $assignedCounsellor = Counsellor::factory()->create(['user_id' => $assignedCounsellorUser->id]);
    $groupTherapy->counsellors()->attach($assignedCounsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);

    expect($groupTherapy->isCounsellor($assignedCounsellor))->toBeTrue();
    expect($groupTherapy->isParticipant($assignedCounsellorUser))->toBeTrue();
});

test('a user attached via the group_therapy_user pivot is recognised as a participant', function () {
    $addedbyUser = User::factory()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $addedbyUser->id,
    ]);

    $memberUser = User::factory()->create();
    $groupTherapy->users()->attach($memberUser->id, ['anonymous' => false]);

    expect($groupTherapy->isParticipant($memberUser))->toBeTrue();
    expect($groupTherapy->isNotParticipant($memberUser))->toBeFalse();
    // isUser() must remain unaffected -- a pivot-attached member is not the addedby.
    expect($groupTherapy->isUser($memberUser))->toBeFalse();
});

test('scopeWhereIsParticipant matches a group therapy via a pivot-attached member', function () {
    $addedbyUser = User::factory()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $addedbyUser->id,
    ]);

    $memberUser = User::factory()->create();
    $groupTherapy->users()->attach($memberUser->id, ['anonymous' => false]);

    $otherGroupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory()->create()->id,
    ]);

    $matches = GroupTherapy::query()->whereIsParticipant($memberUser)->pluck('id');

    expect($matches)->toContain($groupTherapy->id);
    expect($matches)->not->toContain($otherGroupTherapy->id);
});

test('getUsers and getOtherUsers include a pivot-attached member', function () {
    $addedbyUser = User::factory()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $addedbyUser->id,
    ]);

    $memberUser = User::factory()->create();
    $groupTherapy->users()->attach($memberUser->id, ['anonymous' => false]);

    expect($groupTherapy->getUsers()->pluck('id'))->toContain($memberUser->id);
    expect($groupTherapy->getOtherUsers($addedbyUser)->pluck('id'))->toContain($memberUser->id);
    expect($groupTherapy->getOtherUsers($memberUser)->pluck('id'))->not->toContain($memberUser->id);
});

test('a user with no relation to the group therapy is not a participant', function () {
    $addedbyUser = User::factory()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $addedbyUser->id,
    ]);

    $unrelatedUser = User::factory()->create();

    expect($groupTherapy->isParticipant($unrelatedUser))->toBeFalse();
    expect($groupTherapy->isNotParticipant($unrelatedUser))->toBeTrue();
});

test('getUsers includes the addedby user, the counsellor addedby\'s user, and assigned counsellors\' users', function () {
    $addedbyUser = User::factory()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $addedbyUser->id,
    ]);

    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);

    $users = $groupTherapy->getUsers();

    expect($users->pluck('id'))->toContain($addedbyUser->id, $counsellorUser->id);
});

test('getOtherUsers excludes the given user', function () {
    $addedbyUser = User::factory()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $addedbyUser->id,
    ]);

    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);

    $others = $groupTherapy->getOtherUsers($addedbyUser);

    expect($others->pluck('id'))->not->toContain($addedbyUser->id);
    expect($others->pluck('id'))->toContain($counsellorUser->id);
});

test('getCounsellors includes both the counsellor addedby and assigned counsellors', function () {
    $addedbyCounsellorUser = User::factory()->create();
    $addedbyCounsellor = Counsellor::factory()->create(['user_id' => $addedbyCounsellorUser->id]);
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => Counsellor::class,
        'addedby_id' => $addedbyCounsellor->id,
    ]);

    $assignedCounsellorUser = User::factory()->create();
    $assignedCounsellor = Counsellor::factory()->create(['user_id' => $assignedCounsellorUser->id]);
    $groupTherapy->counsellors()->attach($assignedCounsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);

    $counsellors = $groupTherapy->getCounsellors();

    expect($counsellors->pluck('id'))->toContain($addedbyCounsellor->id, $assignedCounsellor->id);
    expect($groupTherapy->getOtherCounsellors($addedbyCounsellor)->pluck('id'))
        ->not->toContain($addedbyCounsellor->id);
});
