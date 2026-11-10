<?php

use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;

test('a fully in-session session with no updatedby is still found as the active session', function () {
    // Regression guard: Session::scopeWhereIsNotUserWhoConfirmedHeld() used to combine a
    // wrong-case `Status` column with independent whereNot/orWhereNot clauses, which excluded
    // any row where `updatedby_type`/`updatedby_id` are null -- true for every session that has
    // gone all the way to IN_SESSION (ChangeSessionStatusAction dissociates updatedby once both
    // parties have confirmed), silently hiding it from getActiveSession() entirely.
    $userUser = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $userUser->id,
        'counsellor_id' => $counsellor->id,
    ]);

    $session = Session::factory()->create([
        'addedby_type' => Counsellor::class,
        'addedby_id' => $counsellor->id,
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
        'status' => 'IN_SESSION',
        'type' => 'ONLINE',
        'start_time' => now()->subMinutes(10),
        'end_time' => now()->addHour(),
        'updatedby_type' => null,
        'updatedby_id' => null,
    ]);

    expect($therapy->getActiveSession($userUser)?->id)->toBe($session->id);
    expect($therapy->getActiveSession($counsellorUser)?->id)->toBe($session->id);
});

test('a group therapy session in the table does not break active-session lookups for an unrelated therapy', function () {
    // Regression guard: Session::scopeWhereIsParticipant()'s whereHasMorph('for', '*', ...)
    // evaluates its closure against every morph type present anywhere in the sessions table --
    // GroupTherapy had no matching scopeWhereIsParticipant(), so Eloquent's magic
    // where{Column} fallback treated it as `where('is_participant', ...)`, a nonexistent
    // column, which threw a SQL error for *every* Session query as soon as any GroupTherapy
    // session existed at all.
    $groupTherapyUser = User::factory()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $groupTherapyUser->id,
    ]);
    Session::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $groupTherapyUser->id,
        'for_id' => $groupTherapy->id,
        'for_type' => GroupTherapy::class,
        'status' => 'PENDING',
    ]);

    $unrelatedUser = User::factory()->create();
    $unrelatedCounsellorUser = User::factory()->create();
    $unrelatedCounsellor = Counsellor::factory()->create(['user_id' => $unrelatedCounsellorUser->id]);
    $unrelatedTherapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $unrelatedUser->id,
        'counsellor_id' => $unrelatedCounsellor->id,
    ]);

    expect(fn () => $unrelatedTherapy->getActiveSession($unrelatedUser))
        ->not->toThrow(Throwable::class);
});

test('an assigned group therapy counsellor is recognised by scopeWhereIsParticipant', function () {
    $addedbyUser = User::factory()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $addedbyUser->id,
    ]);

    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);

    $matches = GroupTherapy::query()->whereIsParticipant($counsellorUser)->whereKey($groupTherapy->id)->exists();

    expect($matches)->toBeTrue();
});
