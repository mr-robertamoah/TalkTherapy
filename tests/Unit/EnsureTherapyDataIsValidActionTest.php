<?php

use App\Actions\Therapy\EnsureTherapyDataIsValidAction;
use App\DTOs\CreateTherapyDTO;
use App\DTOs\GroupTherapyDTO;
use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapySessionTypeEnum;
use App\Exceptions\TherapyCreationDataIsNotValidException;
use App\Models\Counsellor;
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

// SCRUM-132: every cross-field check below reasons about the DTO's current fields only, so a
// partial update omitting one side of a pair either false-positives (the omitted field's null
// looks like "not set" when the persisted value already satisfies the invariant) or bypasses the
// check entirely (the omitted field is what the check's own trigger condition depends on). Each
// pair below is tested in both directions.

test('a partial update setting only paymentType=free does not false-positive when the therapy is already public', function () {
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'public' => true,
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60],
    ]);

    expect(fn () => EnsureTherapyDataIsValidAction::new()->execute(CreateTherapyDTO::new()->fromArray([
        'therapy' => $therapy,
        'paymentType' => TherapyPaymentTypeEnum::free->value,
    ])))->not->toThrow(TherapyCreationDataIsNotValidException::class);
});

test('a partial update setting only public=false does not bypass the FREE-requires-PUBLIC check when the therapy is already free', function () {
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'public' => true,
        'payment_type' => TherapyPaymentTypeEnum::free->value,
    ]);

    expect(fn () => EnsureTherapyDataIsValidAction::new()->execute(CreateTherapyDTO::new()->fromArray([
        'therapy' => $therapy,
        'public' => false,
    ])))->toThrow(TherapyCreationDataIsNotValidException::class, 'FREE payment types requires that you make therapy PUBLIC.');
});

test('a partial update touching an unrelated field does not bypass the ONCE+PAID-must-be-per-THERAPY check', function () {
    // Grandfathered/inconsistent state, same pattern as the other bypass tests: PAID + ONCE
    // but per PER_SESSION, which shouldn't be reachable via this validation going forward but
    // could already exist. A partial update to an unrelated field must still surface it.
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'session_type' => TherapySessionTypeEnum::once->value,
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => 'PER_SESSION', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60],
    ]);

    expect(fn () => EnsureTherapyDataIsValidAction::new()->execute(CreateTherapyDTO::new()->fromArray([
        'therapy' => $therapy,
        'name' => 'Renamed',
    ])))->toThrow(TherapyCreationDataIsNotValidException::class, 'Since ONCE and PAID have been selected for session and payment types respectively, the amount should be per THERAPY.');
});

test('a partial update setting only sessionType=periodic does not false-positive when maxSessions is already valid', function () {
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'session_type' => TherapySessionTypeEnum::once->value,
        'max_sessions' => 5,
    ]);

    expect(fn () => EnsureTherapyDataIsValidAction::new()->execute(CreateTherapyDTO::new()->fromArray([
        'therapy' => $therapy,
        'sessionType' => TherapySessionTypeEnum::periodic->value,
    ])))->not->toThrow(TherapyCreationDataIsNotValidException::class);
});

test('a partial update setting only maxSessions=1 does not bypass the PERIODIC-needs-at-least-2 check when the therapy is already periodic', function () {
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'session_type' => TherapySessionTypeEnum::periodic->value,
        'max_sessions' => 5,
    ]);

    expect(fn () => EnsureTherapyDataIsValidAction::new()->execute(CreateTherapyDTO::new()->fromArray([
        'therapy' => $therapy,
        'maxSessions' => 1,
    ])))->toThrow(TherapyCreationDataIsNotValidException::class, 'Since PERIODIC has been selected for the session type, the maximum number of sessions must be at least 2.');
});

test('a partial update setting only paymentType=paid does not false-positive when amount/currency/per are already set', function () {
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'payment_type' => TherapyPaymentTypeEnum::free->value,
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60],
    ]);

    expect(fn () => EnsureTherapyDataIsValidAction::new()->execute(CreateTherapyDTO::new()->fromArray([
        'therapy' => $therapy,
        'paymentType' => TherapyPaymentTypeEnum::paid->value,
    ])))->not->toThrow(TherapyCreationDataIsNotValidException::class);
});

test('a partial update touching an unrelated field does not bypass the PAID-needs-amount check when the therapy is already paid but incomplete', function () {
    // Simulates a pre-existing inconsistent record (bypassing this validation entirely, exactly
    // as the SCRUM-88 ceiling tests above do) -- payment_type is PAID but currency was never set.
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 50, 'currency' => null, 'inPersonAmount' => 60],
    ]);

    expect(fn () => EnsureTherapyDataIsValidAction::new()->execute(CreateTherapyDTO::new()->fromArray([
        'therapy' => $therapy,
        'name' => 'Renamed',
    ])))->toThrow(TherapyCreationDataIsNotValidException::class, 'Amount, currency and per what? All of these are required since you selected PAID payment type.');
});

test('a partial update touching an unrelated field does not bypass the in-person-amount-vs-amount check when the therapy is already inconsistent', function () {
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 100, 'currency' => 'GHS', 'inPersonAmount' => 50],
    ]);

    expect(fn () => EnsureTherapyDataIsValidAction::new()->execute(CreateTherapyDTO::new()->fromArray([
        'therapy' => $therapy,
        'name' => 'Renamed',
    ])))->toThrow(TherapyCreationDataIsNotValidException::class, 'Amount in-person session cannot be less than amount for online session.');
});

test('a partial group therapy update touching an unrelated field does not bypass the counsellor share-percentage check based on who created it', function () {
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);

    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => Counsellor::class,
        'addedby_id' => $counsellor->id,
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60, 'shareEqually' => false, 'sharePercentage' => 20],
    ]);

    // counsellorIds is never repopulated on GroupTherapyController::updateGroupTherapy the way
    // $dto->counsellor (create-only) would be -- the fix falls back to addedby_type instead.
    expect(fn () => EnsureTherapyDataIsValidAction::new()->execute(GroupTherapyDTO::new()->fromArray([
        'groupTherapy' => $groupTherapy,
        'name' => 'Renamed',
    ])))->toThrow(TherapyCreationDataIsNotValidException::class, 'The share to counsellors cannot be more than 100% or below 40%.');
});

test('a partial group therapy update on a user-owned therapy does not incorrectly fall into the counsellor-owned branch', function () {
    // Complements the test above: confirms $effectiveIsCounsellorOwned correctly resolves to
    // false for a User-created group therapy, not just true for a Counsellor-created one --
    // otherwise this would incorrectly require sharePercentage between 40-100 instead of >=70.
    $owner = User::factory()->create();

    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $owner->id,
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60, 'shareEqually' => false, 'sharePercentage' => 80],
    ]);

    expect(fn () => EnsureTherapyDataIsValidAction::new()->execute(GroupTherapyDTO::new()->fromArray([
        'groupTherapy' => $groupTherapy,
        'name' => 'Renamed',
    ])))->not->toThrow(TherapyCreationDataIsNotValidException::class);
});
