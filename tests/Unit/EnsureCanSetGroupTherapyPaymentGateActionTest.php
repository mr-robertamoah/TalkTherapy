<?php

use App\Actions\GroupTherapy\EnsureCanSetGroupTherapyPaymentGateAction;
use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Exceptions\TherapyException;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\User;

// TT-7.5b-b0/SCRUM-264: GroupTherapy's payment-gate settings are counsellor-controlled, but unlike
// TT-7.5a's single-counsellor Therapy, ANY currently-ACTIVE counsellor on the group may toggle
// them independently -- decision (user-approved 2026-09-11), matching this codebase's existing
// activeCounsellors()/isCounsellor() convention rather than a new, more restrictive shape.

function anActiveCounsellorOn(GroupTherapy $groupTherapy): Counsellor
{
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);

    return $counsellor;
}

test('a group member cannot change the payment gate settings', function () {
    $groupTherapy = GroupTherapy::factory()->create(['public' => true]);
    $member = User::factory()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false]);

    expect(fn () => EnsureCanSetGroupTherapyPaymentGateAction::new()->execute($groupTherapy, $member))
        ->toThrow(TherapyException::class);
});

test('an active counsellor on the group can change the payment gate settings', function () {
    $groupTherapy = GroupTherapy::factory()->create(['public' => true]);
    $counsellor = anActiveCounsellorOn($groupTherapy);

    expect(fn () => EnsureCanSetGroupTherapyPaymentGateAction::new()->execute($groupTherapy, $counsellor->user))
        ->not->toThrow(TherapyException::class);
});

// The whole point of this ticket's own decision: symmetric access, not a single "owning"
// counsellor -- a SECOND, independently-active counsellor is equally authorized.
test('a second active counsellor on the same group can also change the payment gate settings independently', function () {
    $groupTherapy = GroupTherapy::factory()->create(['public' => true]);
    anActiveCounsellorOn($groupTherapy);
    $secondCounsellor = anActiveCounsellorOn($groupTherapy);

    expect(fn () => EnsureCanSetGroupTherapyPaymentGateAction::new()->execute($groupTherapy, $secondCounsellor->user))
        ->not->toThrow(TherapyException::class);
});

test('a counsellor whose assignment to this group has been deactivated cannot change the payment gate settings', function () {
    $groupTherapy = GroupTherapy::factory()->create(['public' => true]);
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::inactive->value, 'role' => 'NORMAL']);

    expect(fn () => EnsureCanSetGroupTherapyPaymentGateAction::new()->execute($groupTherapy, $counsellorUser))
        ->toThrow(TherapyException::class);
});

test('a counsellor on a different group cannot change this group\'s payment gate settings', function () {
    $groupTherapy = GroupTherapy::factory()->create(['public' => true]);
    $otherGroupTherapy = GroupTherapy::factory()->create(['public' => true]);
    $outsiderCounsellor = anActiveCounsellorOn($otherGroupTherapy);

    expect(fn () => EnsureCanSetGroupTherapyPaymentGateAction::new()->execute($groupTherapy, $outsiderCounsellor->user))
        ->toThrow(TherapyException::class);
});

test('a counsellor who created the group directly (addedby) can change the payment gate settings', function () {
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => Counsellor::class,
        'addedby_id' => $counsellor->id,
        'public' => true,
    ]);

    expect(fn () => EnsureCanSetGroupTherapyPaymentGateAction::new()->execute($groupTherapy, $counsellorUser))
        ->not->toThrow(TherapyException::class);
});

test('an admin can change the payment gate settings on any group', function () {
    $groupTherapy = GroupTherapy::factory()->create(['public' => true]);
    $admin = User::factory()->has(Administrator::factory())->create();

    expect(fn () => EnsureCanSetGroupTherapyPaymentGateAction::new()->execute($groupTherapy, $admin))
        ->not->toThrow(TherapyException::class);
});

// Security-review precedent (mirrors TT-7.5a's SCRUM-221 finding): a plain User with no
// counsellor relationship at all must never pass, regardless of anything else about the group.
test('a user with no counsellor account at all cannot change the payment gate settings', function () {
    $groupTherapy = GroupTherapy::factory()->create(['public' => true]);
    $plainUser = User::factory()->create();

    expect(fn () => EnsureCanSetGroupTherapyPaymentGateAction::new()->execute($groupTherapy, $plainUser))
        ->toThrow(TherapyException::class);
});

// Reviewer finding: isCounsellor() never consults the group_therapy_user pivot -- confirms a
// counsellor who holds a real Counsellor account but is only an ordinary member of THIS group
// (not attached via counsellor_group_therapy) cannot piggyback on that membership to authorize.
test('a counsellor account holder who is only an ordinary member of this group cannot change the payment gate settings', function () {
    $groupTherapy = GroupTherapy::factory()->create(['public' => true]);
    $counsellorUser = User::factory()->create();
    Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $groupTherapy->users()->attach($counsellorUser->id, ['anonymous' => false]);

    expect(fn () => EnsureCanSetGroupTherapyPaymentGateAction::new()->execute($groupTherapy, $counsellorUser))
        ->toThrow(TherapyException::class);
});
