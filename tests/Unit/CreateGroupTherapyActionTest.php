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
