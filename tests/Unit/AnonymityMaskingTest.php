<?php

use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;

// SCRUM-71: one source of truth for whether a given sender's identity is masked. SCRUM-380:
// isAnonymousFor() was removed; its callers (message and session ids, the assistance-accepted
// notification, comments) use TherapyTrait::hidesIdentityOf(), which keeps every case below except
// the last group one, a deliberate fail-closed change (HidesIdentityCallersTest has every caller's cases).
describe('Therapy::hidesIdentityOf()', function () {
    test('is true when the therapy is anonymous', function () {
        $user = User::factory()->create();
        $therapy = Therapy::factory()->create([
            'addedby_type' => User::class,
            'addedby_id' => $user->id,
            'anonymous' => true,
        ]);

        expect($therapy->hidesIdentityOf($user))->toBeTrue();
    });

    test('is false when the therapy is not anonymous', function () {
        $user = User::factory()->create();
        $therapy = Therapy::factory()->create([
            'addedby_type' => User::class,
            'addedby_id' => $user->id,
            'anonymous' => false,
        ]);

        expect($therapy->hidesIdentityOf($user))->toBeFalse();
    });
});

describe('GroupTherapy::hidesIdentityOf()', function () {
    test('is true when the group itself is anonymous, regardless of the member pivot', function () {
        $member = User::factory()->create();
        $groupTherapy = GroupTherapy::factory()->create(['anonymous' => true]);
        $groupTherapy->users()->attach($member->id, ['anonymous' => false]);

        expect($groupTherapy->fresh()->hidesIdentityOf($member))->toBeTrue();
    });

    test('is true when only the member\'s own pivot flag is set, even though the group is not anonymous', function () {
        $member = User::factory()->create();
        $groupTherapy = GroupTherapy::factory()->create(['anonymous' => false]);
        $groupTherapy->users()->attach($member->id, ['anonymous' => true]);

        expect($groupTherapy->fresh()->hidesIdentityOf($member))->toBeTrue();
    });

    test('is false when neither the group nor the member pivot is anonymous', function () {
        $member = User::factory()->create();
        $groupTherapy = GroupTherapy::factory()->create(['anonymous' => false]);
        $groupTherapy->users()->attach($member->id, ['anonymous' => false]);

        expect($groupTherapy->fresh()->hidesIdentityOf($member))->toBeFalse();
    });

    // SCRUM-380 (deliberate change): the old rule showed someone with no membership row (someone who
    // left, an administrator), because the row that held their anonymity was gone.
    test('is true for someone with no pivot row at all, even when the group is not anonymous', function () {
        $nonMember = User::factory()->create();
        $groupTherapy = GroupTherapy::factory()->create(['anonymous' => false]);

        expect($groupTherapy->fresh()->hidesIdentityOf($nonMember))->toBeTrue();
    });
});

describe('Session::hidesIdentityOf()', function () {
    test('passes through to an individual Therapy\'s hidesIdentityOf()', function () {
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

        expect($session->hidesIdentityOf($user))->toBeTrue();
    });

    test('passes through to a GroupTherapy\'s hidesIdentityOf()', function () {
        $member = User::factory()->create();
        $groupTherapy = GroupTherapy::factory()->create(['anonymous' => false]);
        $groupTherapy->users()->attach($member->id, ['anonymous' => true]);
        $session = Session::factory()->create([
            'for_id' => $groupTherapy->id,
            'for_type' => GroupTherapy::class,
        ]);

        expect($session->fresh()->hidesIdentityOf($member))->toBeTrue();
    });
});
