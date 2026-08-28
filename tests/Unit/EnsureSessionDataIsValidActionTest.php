<?php

use App\Actions\Session\EnsureSessionDataIsValidAction;
use App\DTOs\CreateSessionDTO;
use App\Enums\SessionTypeEnum;
use App\Exceptions\SessionException;
use App\Models\Counsellor;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use Carbon\Carbon;

test('whereIsThirtyMinituesBeforeOrAfter does not mutate the given start/end times', function () {
    $start = Carbon::parse('2025-06-01 09:00:00');
    $end = Carbon::parse('2025-06-01 10:00:00');

    Session::query()->whereIsThirtyMinituesBeforeOrAfter($start, $end);
    Session::query()->whereIsThirtyMinituesBeforeOrAfter($start, $end);

    expect($start->toDateTimeString())->toBe('2025-06-01 09:00:00');
    expect($end->toDateTimeString())->toBe('2025-06-01 10:00:00');
});

test('rescheduling to an earlier, genuinely free slot does not throw a false prohibition error', function () {
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);

    $therapyBeingRescheduled = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => $counsellor->id,
        // sessionsHeld (an accessor, always an int, 0 by default) is compared with `==` against
        // max_sessions in EnsureSessionDataIsValidAction; without this, 0 == null is true in PHP
        // and the "max sessions reached" branch would fire before the checks under test run.
        'max_sessions' => 10,
    ]);

    $sessionBeingRescheduled = Session::factory()->create([
        'for_id' => $therapyBeingRescheduled->id,
        'for_type' => Therapy::class,
        'start_time' => Carbon::parse('2025-06-01 20:00:00'),
        'end_time' => Carbon::parse('2025-06-01 21:00:00'),
    ]);

    // An unrelated session for the same counsellor, on a different therapy, that a genuinely
    // free earlier slot should NOT collide with.
    $otherTherapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => $counsellor->id,
    ]);
    Session::factory()->create([
        'for_id' => $otherTherapy->id,
        'for_type' => Therapy::class,
        'start_time' => Carbon::parse('2025-06-01 11:00:00'),
        'end_time' => Carbon::parse('2025-06-01 12:00:00'),
    ]);

    $dto = CreateSessionDTO::new()->fromArray([
        'user' => $counsellorUser,
        'for' => $therapyBeingRescheduled,
        'session' => $sessionBeingRescheduled,
        'startTime' => '2025-06-01 09:00:00',
        'endTime' => '2025-06-01 10:00:00',
        'type' => $sessionBeingRescheduled->type,
        'paymentType' => $sessionBeingRescheduled->payment_type,
    ]);

    expect(fn () => EnsureSessionDataIsValidAction::new()->execute($dto))
        ->not->toThrow(SessionException::class);
});

// Regression tests for SCRUM-129: the "user"/"counsellor" double-booking checks used
// whereDoesntHave('for', whereParticipant($person)) -- scanning sessions belonging to therapies
// the person does NOT participate in, the opposite of what a "does this person already have a
// conflicting session" check should scope to. Flipped to whereHas.

test('creating a session conflicts with the same user\'s own overlapping session on a different therapy', function () {
    $owner = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);

    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $owner->id,
        'counsellor_id' => $counsellor->id,
        'max_sessions' => 10,
    ]);

    // The same owner's OTHER therapy, with a session starting only 15 minutes after the proposed
    // one ends -- less than the required 30-minute gap -- so this should be caught as a real
    // conflict.
    $otherTherapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $owner->id,
        'max_sessions' => 10,
    ]);
    Session::factory()->create([
        'for_id' => $otherTherapy->id,
        'for_type' => Therapy::class,
        'start_time' => Carbon::parse('2025-06-01 10:15:00'),
        'end_time' => Carbon::parse('2025-06-01 11:15:00'),
    ]);

    $dto = CreateSessionDTO::new()->fromArray([
        'user' => $owner,
        'for' => $therapy,
        'startTime' => '2025-06-01 09:00:00',
        'endTime' => '2025-06-01 10:00:00',
        'type' => SessionTypeEnum::online->value,
        'paymentType' => $therapy->payment_type,
    ]);

    expect(fn () => EnsureSessionDataIsValidAction::new()->execute($dto))
        ->toThrow(SessionException::class, 'The user has sessions that are less than 30 minutes before or after the time for this session.');
});

test('creating a session does not conflict with an unrelated session on a therapy the user has nothing to do with', function () {
    $owner = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);

    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $owner->id,
        'counsellor_id' => $counsellor->id,
        'max_sessions' => 10,
    ]);

    // A totally unrelated therapy (different owner, different counsellor) with a session starting
    // only 15 minutes after the proposed one ends -- this must NOT be treated as this user's
    // conflict just because it happens to land in the same 30-minute window.
    $unrelatedTherapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'max_sessions' => 10,
    ]);
    Session::factory()->create([
        'for_id' => $unrelatedTherapy->id,
        'for_type' => Therapy::class,
        'start_time' => Carbon::parse('2025-06-01 10:15:00'),
        'end_time' => Carbon::parse('2025-06-01 11:15:00'),
    ]);

    $dto = CreateSessionDTO::new()->fromArray([
        'user' => $owner,
        'for' => $therapy,
        'startTime' => '2025-06-01 09:00:00',
        'endTime' => '2025-06-01 10:00:00',
        'type' => SessionTypeEnum::online->value,
        'paymentType' => $therapy->payment_type,
    ]);

    expect(fn () => EnsureSessionDataIsValidAction::new()->execute($dto))
        ->not->toThrow(SessionException::class);
});
