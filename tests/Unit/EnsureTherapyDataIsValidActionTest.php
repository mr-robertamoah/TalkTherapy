<?php

use App\Actions\Therapy\EnsureTherapyDataIsValidAction;
use App\DTOs\CreateTherapyDTO;
use App\DTOs\GroupTherapyDTO;
use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapySessionTypeEnum;
use App\Exceptions\TherapyCreationDataIsNotValidException;
use App\Models\GroupTherapy;
use App\Models\Therapy;
use App\Models\User;

function aValidCreateTherapyDTO(array $overrides = [])
{
    return CreateTherapyDTO::new()->fromArray(array_merge([
        'public' => true,
        'sessionType' => TherapySessionTypeEnum::once->value,
        'paymentType' => TherapyPaymentTypeEnum::free->value,
    ], $overrides));
}

function aValidGroupTherapyDTO(array $overrides = [])
{
    return GroupTherapyDTO::new()->fromArray(array_merge([
        'public' => true,
        'sessionType' => TherapySessionTypeEnum::once->value,
        'paymentType' => TherapyPaymentTypeEnum::free->value,
    ], $overrides));
}

test('an individual therapy with maxSessions above the sanity ceiling is rejected (SCRUM-84)', function () {
    expect(fn () => EnsureTherapyDataIsValidAction::new()->execute(aValidCreateTherapyDTO(['maxSessions' => 101])))
        ->toThrow(TherapyCreationDataIsNotValidException::class);
});

test('a group therapy with maxSessions above the sanity ceiling is rejected (SCRUM-84)', function () {
    expect(fn () => EnsureTherapyDataIsValidAction::new()->execute(aValidGroupTherapyDTO(['maxSessions' => 101])))
        ->toThrow(TherapyCreationDataIsNotValidException::class);
});

test('maxSessions at the default ceiling is still accepted', function () {
    expect(fn () => EnsureTherapyDataIsValidAction::new()->execute(aValidCreateTherapyDTO(['maxSessions' => 100])))
        ->not->toThrow(TherapyCreationDataIsNotValidException::class);
});

test('an omitted maxSessions on a non-periodic therapy is still accepted', function () {
    expect(fn () => EnsureTherapyDataIsValidAction::new()->execute(aValidCreateTherapyDTO()))
        ->not->toThrow(TherapyCreationDataIsNotValidException::class);
});

// SCRUM-88: a record created before this ceiling existed (or before an env var lowered it)
// could already be sitting above it. Since edit forms resend the current, unchanged value
// alongside whatever the user actually changed, that stored value must not trip the ceiling
// check just because it was resent -- simulated here by creating a Therapy/GroupTherapy row
// directly above the ceiling (bypassing this validation action entirely, exactly as a
// pre-existing/grandfathered row would), independent of whether any such row currently exists
// in a real deployment.

test('resending an unrelated update with an already-over-ceiling, UNCHANGED maxSessions is accepted (SCRUM-88)', function () {
    // Read the real configured ceiling rather than hardcoding a number that could coincidentally
    // sit under whatever THERAPY_MAX_SESSIONS actually is in a given environment (this exact
    // mistake was caught here during review: an earlier draft of the maxUsers test below used a
    // hardcoded 75, which never exceeded this project's real GROUP_THERAPY_MAX_USERS=100).
    $overCeiling = env('THERAPY_MAX_SESSIONS', 100) + 50;

    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'max_sessions' => $overCeiling,
    ]);

    expect(fn () => EnsureTherapyDataIsValidAction::new()->execute(aValidCreateTherapyDTO([
        'therapy' => $therapy,
        'maxSessions' => $overCeiling,
    ])))->not->toThrow(TherapyCreationDataIsNotValidException::class);
});

test('genuinely increasing an already-over-ceiling maxSessions further is still rejected (SCRUM-88)', function () {
    $overCeiling = env('THERAPY_MAX_SESSIONS', 100) + 50;

    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'max_sessions' => $overCeiling,
    ]);

    expect(fn () => EnsureTherapyDataIsValidAction::new()->execute(aValidCreateTherapyDTO([
        'therapy' => $therapy,
        'maxSessions' => $overCeiling + 50,
    ])))->toThrow(TherapyCreationDataIsNotValidException::class);
});

test('resending an unrelated update with an already-over-ceiling, UNCHANGED maxCounsellors is accepted (SCRUM-88)', function () {
    $overCeiling = env('GROUP_THERAPY_MAX_COUNSELLORS', 10) + 5;

    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'max_counsellors' => $overCeiling,
    ]);

    expect(fn () => EnsureTherapyDataIsValidAction::new()->execute(aValidGroupTherapyDTO([
        'groupTherapy' => $groupTherapy,
        'maxCounsellors' => $overCeiling,
    ])))->not->toThrow(TherapyCreationDataIsNotValidException::class);
});

test('genuinely increasing an already-over-ceiling maxCounsellors further is still rejected (SCRUM-88)', function () {
    $overCeiling = env('GROUP_THERAPY_MAX_COUNSELLORS', 10) + 5;

    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'max_counsellors' => $overCeiling,
    ]);

    expect(fn () => EnsureTherapyDataIsValidAction::new()->execute(aValidGroupTherapyDTO([
        'groupTherapy' => $groupTherapy,
        'maxCounsellors' => $overCeiling + 5,
    ])))->toThrow(TherapyCreationDataIsNotValidException::class);
});

test('resending an unrelated update with an already-over-ceiling, UNCHANGED maxUsers is accepted (SCRUM-88)', function () {
    $overCeiling = env('GROUP_THERAPY_MAX_USERS', 50) + 25;

    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'max_users' => $overCeiling,
    ]);

    expect(fn () => EnsureTherapyDataIsValidAction::new()->execute(aValidGroupTherapyDTO([
        'groupTherapy' => $groupTherapy,
        'maxUsers' => $overCeiling,
    ])))->not->toThrow(TherapyCreationDataIsNotValidException::class);
});

test('genuinely increasing an already-over-ceiling maxUsers further is still rejected (SCRUM-88)', function () {
    $overCeiling = env('GROUP_THERAPY_MAX_USERS', 50) + 25;

    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'max_users' => $overCeiling,
    ]);

    expect(fn () => EnsureTherapyDataIsValidAction::new()->execute(aValidGroupTherapyDTO([
        'groupTherapy' => $groupTherapy,
        'maxUsers' => $overCeiling + 25,
    ])))->toThrow(TherapyCreationDataIsNotValidException::class);
});
