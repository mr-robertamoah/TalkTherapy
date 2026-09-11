<?php

use App\Actions\GroupTherapy\GetGroupTherapyMemberJoinDateAction;
use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\User;

// TT-7.5b-b3/SCRUM-267: resolves the late-joiner exemption's own comparison point.

test('a pivot-joined member\'s join date is their own group_therapy_user.created_at', function () {
    $groupTherapy = GroupTherapy::factory()->create();
    $member = User::factory()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false, 'created_at' => now()->subDays(3)]);

    $joinDate = GetGroupTherapyMemberJoinDateAction::new()->execute($groupTherapy, $member);

    expect($joinDate)->not->toBeNull()
        ->and($joinDate->toDateTimeString())->toBe(now()->subDays(3)->toDateTimeString());
});

test('a User-type creator with no pivot row has the group\'s own creation date as their join date', function () {
    $creator = User::factory()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $creator->id,
        'created_at' => now()->subDays(10),
    ]);

    $joinDate = GetGroupTherapyMemberJoinDateAction::new()->execute($groupTherapy, $creator);

    expect($joinDate)->not->toBeNull()
        ->and($joinDate->toDateTimeString())->toBe($groupTherapy->created_at->toDateTimeString());
});

// A creator who ALSO holds a pivot row (defensive edge case, in practice a creator never
// separately joins) prefers the pivot row's own created_at over the group's creation date.
test('a creator who also holds a pivot row uses the pivot row\'s own created_at', function () {
    $creator = User::factory()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $creator->id,
        'created_at' => now()->subDays(10),
    ]);
    $groupTherapy->users()->attach($creator->id, ['anonymous' => false, 'created_at' => now()->subDays(2)]);

    $joinDate = GetGroupTherapyMemberJoinDateAction::new()->execute($groupTherapy, $creator);

    expect($joinDate->toDateTimeString())->toBe(now()->subDays(2)->toDateTimeString());
});

test('a non-member (e.g. an unrelated user, or a counsellor never attached at all) has no join date', function () {
    $groupTherapy = GroupTherapy::factory()->create();
    $unrelatedUser = User::factory()->create();

    expect(GetGroupTherapyMemberJoinDateAction::new()->execute($groupTherapy, $unrelatedUser))->toBeNull();
});

test('a counsellor attached only via counsellors() (never joined as a member) has no join date', function () {
    $groupTherapy = GroupTherapy::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);

    expect(GetGroupTherapyMemberJoinDateAction::new()->execute($groupTherapy, $counsellorUser))->toBeNull();
});
