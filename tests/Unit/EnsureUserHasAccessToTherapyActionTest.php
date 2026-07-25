<?php

use App\Actions\Therapy\EnsureUserHasAccessToTherapyAction;
use App\DTOs\GetTherapyDTO;
use App\Exceptions\TherapyAccessDeniedException;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\User;

test('the addedby user of a non-public group therapy is granted access', function () {
    $addedbyUser = User::factory()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $addedbyUser->id,
        'public' => false,
    ]);

    $dto = GetTherapyDTO::new()->fromArray([
        'user' => $addedbyUser,
        'groupTherapy' => $groupTherapy,
    ]);

    expect(fn () => EnsureUserHasAccessToTherapyAction::new()->execute($dto, 'groupTherapy'))
        ->not->toThrow(TherapyAccessDeniedException::class);
});

test('an assigned counsellor of a non-public group therapy is granted access', function () {
    $addedbyUser = User::factory()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $addedbyUser->id,
        'public' => false,
    ]);

    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);

    $dto = GetTherapyDTO::new()->fromArray([
        'user' => $counsellorUser,
        'groupTherapy' => $groupTherapy,
    ]);

    expect(fn () => EnsureUserHasAccessToTherapyAction::new()->execute($dto, 'groupTherapy'))
        ->not->toThrow(TherapyAccessDeniedException::class);
});

test('an unrelated user is denied access to a non-public group therapy', function () {
    // This is the fail-closed regression guard: before this fix, isParticipant() always
    // returned false AND isGuardianOfAUserFor() always returned true, so this exact scenario
    // was silently granting access to everyone. Both bugs had to be fixed together for this
    // to correctly deny access.
    $addedbyUser = User::factory()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $addedbyUser->id,
        'public' => false,
    ]);

    $unrelatedUser = User::factory()->create();

    $dto = GetTherapyDTO::new()->fromArray([
        'user' => $unrelatedUser,
        'groupTherapy' => $groupTherapy,
    ]);

    expect(fn () => EnsureUserHasAccessToTherapyAction::new()->execute($dto, 'groupTherapy'))
        ->toThrow(TherapyAccessDeniedException::class);
});

test('any authenticated user is granted access to a public group therapy regardless of participation', function () {
    $addedbyUser = User::factory()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $addedbyUser->id,
        'public' => true,
    ]);

    $unrelatedUser = User::factory()->create();

    $dto = GetTherapyDTO::new()->fromArray([
        'user' => $unrelatedUser,
        'groupTherapy' => $groupTherapy,
    ]);

    expect(fn () => EnsureUserHasAccessToTherapyAction::new()->execute($dto, 'groupTherapy'))
        ->not->toThrow(TherapyAccessDeniedException::class);
});
