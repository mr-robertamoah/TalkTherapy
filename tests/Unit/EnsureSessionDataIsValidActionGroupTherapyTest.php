<?php

use App\Actions\Session\EnsureSessionDataIsValidAction;
use App\DTOs\CreateSessionDTO;
use App\Enums\CounsellorGroupTherapyRoleEnum;
use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Enums\SessionStatusEnum;
use App\Enums\SessionTypeEnum;
use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Exceptions\SessionException;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use Carbon\Carbon;

// Regression tests for SCRUM-108: validateGroupTherapy() was a completely empty stub, so a
// session for a group therapy could be created regardless of the group therapy's own ended
// status, max_sessions ceiling, payment type, allow_in_person flag, or scheduling conflicts.

function aValidGroupTherapySessionDTO(GroupTherapy $groupTherapy, array $overrides = [])
{
    return CreateSessionDTO::new()->fromArray(array_merge([
        'for' => $groupTherapy,
        'type' => SessionTypeEnum::online->value,
        'paymentType' => $groupTherapy->payment_type,
        'startTime' => Carbon::parse('2025-06-01 09:00:00'),
        'endTime' => Carbon::parse('2025-06-01 10:00:00'),
    ], $overrides));
}

// sessionsHeld (an accessor, always an int, 0 by default) is compared with `==` against
// max_sessions in EnsureSessionDataIsValidAction; without an explicit value, a freshly-created
// model doesn't reflect the DB column's default(10) in memory, so max_sessions is null and
// 0 == null is true in PHP -- the "max sessions reached" branch would fire before the checks
// under test run. Mirrors the same workaround in EnsureSessionDataIsValidActionTest.php.
function aGroupTherapyWithRoomForSessions(array $overrides = [])
{
    return GroupTherapy::factory()->create(array_merge([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'max_sessions' => 10,
    ], $overrides));
}

test('a session cannot be created for an ended group therapy', function () {
    $groupTherapy = aGroupTherapyWithRoomForSessions([
        'status' => TherapyStatusEnum::ended->value,
    ]);

    expect(fn () => EnsureSessionDataIsValidAction::new()->execute(aValidGroupTherapySessionDTO($groupTherapy)))
        ->toThrow(SessionException::class, 'You cannot a create session for a group therapy which has ended.');
});

test('a session cannot be created once the group therapy\'s max_sessions has been reached', function () {
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'max_sessions' => 1,
    ]);
    // sessionsHeld only counts sessions with status HELD (App\Traits\TherapyTrait::getSessionsHeldAttribute()).
    Session::factory()->create([
        'for_id' => $groupTherapy->id,
        'for_type' => GroupTherapy::class,
        'status' => SessionStatusEnum::held->value,
    ]);

    expect(fn () => EnsureSessionDataIsValidAction::new()->execute(aValidGroupTherapySessionDTO($groupTherapy)))
        ->toThrow(SessionException::class, 'You cannot create a session because the maximum session for this group therapy has been reached.');
});

test('a PAID session cannot be created for a FREE group therapy', function () {
    $groupTherapy = aGroupTherapyWithRoomForSessions([
        'payment_type' => TherapyPaymentTypeEnum::free->value,
    ]);

    expect(fn () => EnsureSessionDataIsValidAction::new()->execute(aValidGroupTherapySessionDTO($groupTherapy, [
        'paymentType' => TherapyPaymentTypeEnum::paid->value,
    ])))->toThrow(SessionException::class, 'You cannot create a PAID session for a FREE group therapy.');
});

test('an in-person session cannot be created for a group therapy that does not allow in-person sessions', function () {
    $groupTherapy = aGroupTherapyWithRoomForSessions([
        'allow_in_person' => false,
    ]);

    expect(fn () => EnsureSessionDataIsValidAction::new()->execute(aValidGroupTherapySessionDTO($groupTherapy, [
        'type' => SessionTypeEnum::in_person->value,
    ])))->toThrow(SessionException::class, 'You cannot create an in-persion session for a group therapy that does not allow in-person sessions.');
});

test('a session cannot start within another session of the same group therapy', function () {
    $groupTherapy = aGroupTherapyWithRoomForSessions();
    Session::factory()->create([
        'for_id' => $groupTherapy->id,
        'for_type' => GroupTherapy::class,
        'start_time' => Carbon::parse('2025-06-01 08:30:00'),
        'end_time' => Carbon::parse('2025-06-01 09:30:00'),
    ]);

    expect(fn () => EnsureSessionDataIsValidAction::new()->execute(aValidGroupTherapySessionDTO($groupTherapy)))
        ->toThrow(SessionException::class, 'The start time of a session cannot fall within the start and end time of other sessions.');
});

test('a session conflicts with the addedby user\'s own overlapping session on a different group therapy', function () {
    $owner = User::factory()->create();

    $groupTherapy = aGroupTherapyWithRoomForSessions([
        'addedby_id' => $owner->id,
    ]);

    $otherGroupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $owner->id,
    ]);
    Session::factory()->create([
        'for_id' => $otherGroupTherapy->id,
        'for_type' => GroupTherapy::class,
        'start_time' => Carbon::parse('2025-06-01 10:15:00'),
        'end_time' => Carbon::parse('2025-06-01 11:15:00'),
    ]);

    expect(fn () => EnsureSessionDataIsValidAction::new()->execute(aValidGroupTherapySessionDTO($groupTherapy)))
        ->toThrow(SessionException::class, 'The user has sessions that are less than 30 minutes before or after the time for this session.');
});

test('a session conflicts with a pivot-attached counsellor\'s overlapping session on a different group therapy', function () {
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);

    $groupTherapy = aGroupTherapyWithRoomForSessions();
    $groupTherapy->counsellors()->attach($counsellor->id, [
        'state' => CounsellorGroupTherapyStateEnum::active->value,
        'role' => CounsellorGroupTherapyRoleEnum::normal->value,
    ]);

    $otherTherapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => $counsellor->id,
    ]);
    Session::factory()->create([
        'for_id' => $otherTherapy->id,
        'for_type' => Therapy::class,
        'start_time' => Carbon::parse('2025-06-01 10:15:00'),
        'end_time' => Carbon::parse('2025-06-01 11:15:00'),
    ]);

    expect(fn () => EnsureSessionDataIsValidAction::new()->execute(aValidGroupTherapySessionDTO($groupTherapy)))
        ->toThrow(SessionException::class, 'Counsellor for this group therapy has sessions that are less than 30 minutes before or after the time for this session.');
});

test('a valid session for a healthy group therapy is accepted', function () {
    $groupTherapy = aGroupTherapyWithRoomForSessions();

    expect(fn () => EnsureSessionDataIsValidAction::new()->execute(aValidGroupTherapySessionDTO($groupTherapy)))
        ->not->toThrow(SessionException::class);
});
