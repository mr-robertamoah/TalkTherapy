<?php

use App\Enums\SessionStatusEnum;
use App\Models\Counsellor;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use Carbon\Carbon;

// Regression test for a bug found while implementing SCRUM-134's browser QA: the three OR'd
// conditions in Counsellor::hasPendingSessions() weren't grouped in one outer where(), so only
// wherePending() stayed scoped to this counsellor's addedSessions() -- the trailing orWhere()s
// broke out to match ANY session in the whole sessions table (the same where()->orWhere()
// footgun already fixed elsewhere this sweep as SCRUM-129/139). This silently made
// EnsureCanDeleteCounsellorAction reject deletion for every counsellor as soon as any session
// anywhere in the system was upcoming/about to start.

test('an unrelated counsellor\'s upcoming session does not count as this counsellor\'s pending session', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);

    $otherCounsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $otherTherapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => $otherCounsellor->id,
    ]);
    Session::factory()->create([
        'for_id' => $otherTherapy->id,
        'for_type' => Therapy::class,
        'addedby_type' => Counsellor::class,
        'addedby_id' => $otherCounsellor->id,
        'start_time' => Carbon::now()->addHour(),
        'end_time' => Carbon::now()->addHours(2),
    ]);

    expect($counsellor->hasPendingSessions())->toBeFalse();
});

test('this counsellor\'s own pending session is correctly detected', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => $counsellor->id,
    ]);
    Session::factory()->create([
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
        'addedby_type' => Counsellor::class,
        'addedby_id' => $counsellor->id,
        'status' => SessionStatusEnum::pending->value,
    ]);

    expect($counsellor->hasPendingSessions())->toBeTrue();
});

test('this counsellor\'s own about-to-start session is correctly detected', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => $counsellor->id,
    ]);
    Session::factory()->create([
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
        'addedby_type' => Counsellor::class,
        'addedby_id' => $counsellor->id,
        'start_time' => Carbon::now()->addMinutes(10),
        'end_time' => Carbon::now()->addHour(),
    ]);

    expect($counsellor->hasPendingSessions())->toBeTrue();
});

test('a counsellor with no sessions at all has no pending sessions', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);

    expect($counsellor->hasPendingSessions())->toBeFalse();
});
