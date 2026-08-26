<?php

use App\Actions\GroupTherapy\CreateGroupTherapyAction;
use App\DTOs\GroupTherapyDTO;
use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapySessionTypeEnum;
use App\Models\User;

function aGroupTherapyDTO(array $overrides = [])
{
    return GroupTherapyDTO::new()->fromArray(array_merge([
        'user' => User::factory()->create(),
        'name' => 'Test Group',
        'about' => 'A group for testing',
        'public' => true,
        'anonymous' => false,
        'allowInPerson' => false,
        'allowAnyone' => false,
        'sessionType' => TherapySessionTypeEnum::once->value,
        'paymentType' => TherapyPaymentTypeEnum::free->value,
    ], $overrides));
}

test('creating a group therapy without maxUsers does not crash and falls back to the DB default', function () {
    $therapy = CreateGroupTherapyAction::new()->execute(aGroupTherapyDTO());

    expect($therapy->max_users)->toBe(10);
});

test('creating a group therapy without maxSessions does not crash and falls back to the DB default', function () {
    $therapy = CreateGroupTherapyAction::new()->execute(aGroupTherapyDTO());

    expect($therapy->max_sessions)->toBe(10);
});

test('creating a group therapy with an explicit maxUsers still honours the provided value', function () {
    $therapy = CreateGroupTherapyAction::new()->execute(aGroupTherapyDTO(['maxUsers' => 25]));

    expect($therapy->max_users)->toBe(25);
});

test('creating a group therapy with an explicit maxSessions still honours the provided value', function () {
    $therapy = CreateGroupTherapyAction::new()->execute(aGroupTherapyDTO([
        'sessionType' => TherapySessionTypeEnum::periodic->value,
        'maxSessions' => 3,
    ]));

    expect($therapy->max_sessions)->toBe(3);
});

test('creating a group therapy without maxCounsellors falls back to the DB default', function () {
    $therapy = CreateGroupTherapyAction::new()->execute(aGroupTherapyDTO());

    expect($therapy->max_counsellors)->toBe(5);
});

test('creating a group therapy with an explicit maxCounsellors persists the provided value (SCRUM-83)', function () {
    $therapy = CreateGroupTherapyAction::new()->execute(aGroupTherapyDTO(['maxCounsellors' => 3]));

    expect($therapy->max_counsellors)->toBe(3);
});

test('creating a group therapy persists allowInPerson=true instead of silently dropping it (SCRUM-86)', function () {
    // Deliberately only testing true=>true here, not false=>false: the allow_in_person
    // column's own DB default is false, so a false=>false test would pass even if this key
    // were still being silently dropped by mass-assignment guarding -- it wouldn't discriminate
    // the bug at all. The false case is meaningfully covered instead by the companion
    // UpdateGroupTherapyActionAllowInPersonTest, which starts from an explicit false and
    // transitions to true.
    $therapy = CreateGroupTherapyAction::new()->execute(aGroupTherapyDTO(['allowInPerson' => true]));

    // allow_in_person isn't cast to bool on the model (GroupTherapyResource does its own (bool)
    // cast, mirroring TherapyResource), so the raw attribute is the DB's native 1/0.
    expect((bool) $therapy->allow_in_person)->toBeTrue();
});
