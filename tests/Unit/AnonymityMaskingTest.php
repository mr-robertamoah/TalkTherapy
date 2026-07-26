<?php

use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;

// SCRUM-71: Therapy/GroupTherapy/Session::isAnonymousFor() are the single source of truth for
// whether a given sender's identity should be masked, consumed by MessageResource, the four
// "leaking" list resources, and the presence-channel closures in routes/channels.php.
describe('Therapy::isAnonymousFor()', function () {
    test('is true when the therapy is anonymous', function () {
        $user = User::factory()->create();
        $therapy = Therapy::factory()->create([
            'addedby_type' => User::class,
            'addedby_id' => $user->id,
            'anonymous' => true,
        ]);

        expect($therapy->isAnonymousFor($user))->toBeTrue();
    });

    test('is false when the therapy is not anonymous', function () {
        $user = User::factory()->create();
        $therapy = Therapy::factory()->create([
            'addedby_type' => User::class,
            'addedby_id' => $user->id,
            'anonymous' => false,
        ]);

        expect($therapy->isAnonymousFor($user))->toBeFalse();
    });
});

describe('GroupTherapy::isAnonymousFor()', function () {
    test('is true when the group itself is anonymous, regardless of the member pivot', function () {
        $member = User::factory()->create();
        $groupTherapy = GroupTherapy::factory()->create(['anonymous' => true]);
        $groupTherapy->users()->attach($member->id, ['anonymous' => false]);

        expect($groupTherapy->fresh()->isAnonymousFor($member))->toBeTrue();
    });

    test('is true when only the member\'s own pivot flag is set, even though the group is not anonymous', function () {
        $member = User::factory()->create();
        $groupTherapy = GroupTherapy::factory()->create(['anonymous' => false]);
        $groupTherapy->users()->attach($member->id, ['anonymous' => true]);

        expect($groupTherapy->fresh()->isAnonymousFor($member))->toBeTrue();
    });

    test('is false when neither the group nor the member pivot is anonymous', function () {
        $member = User::factory()->create();
        $groupTherapy = GroupTherapy::factory()->create(['anonymous' => false]);
        $groupTherapy->users()->attach($member->id, ['anonymous' => false]);

        expect($groupTherapy->fresh()->isAnonymousFor($member))->toBeFalse();
    });

    test('is false for a member with no pivot row at all, when the group is not anonymous', function () {
        $nonMember = User::factory()->create();
        $groupTherapy = GroupTherapy::factory()->create(['anonymous' => false]);

        expect($groupTherapy->fresh()->isAnonymousFor($nonMember))->toBeFalse();
    });
});

describe('Session::isAnonymousFor()', function () {
    test('passes through to an individual Therapy\'s isAnonymousFor()', function () {
        $user = User::factory()->create();
        $therapy = Therapy::factory()->create([
            'addedby_type' => User::class,
            'addedby_id' => $user->id,
            'anonymous' => true,
        ]);
        $session = Session::factory()->create([
            'for_id' => $therapy->id,
            'for_type' => Therapy::class,
        ]);

        expect($session->isAnonymousFor($user))->toBeTrue();
    });

    test('passes through to a GroupTherapy\'s isAnonymousFor()', function () {
        $member = User::factory()->create();
        $groupTherapy = GroupTherapy::factory()->create(['anonymous' => false]);
        $groupTherapy->users()->attach($member->id, ['anonymous' => true]);
        $session = Session::factory()->create([
            'for_id' => $groupTherapy->id,
            'for_type' => GroupTherapy::class,
        ]);

        expect($session->fresh()->isAnonymousFor($member))->toBeTrue();
    });
});
