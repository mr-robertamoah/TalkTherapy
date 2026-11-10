<?php

use App\Actions\GroupTherapy\CreateGroupTherapyAction;
use App\DTOs\GroupTherapyDTO;
use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapySessionTypeEnum;
use App\Models\User;

// TT-7.5b-b1/SCRUM-265: strictPaymentGate/allowFreeHistoricalAccess on GroupTherapy creation.
// Deliberately no authorization test here -- whoever creates the group sets its own initial
// values, same as every other creation field; there is no prior state for anyone else to protect
// yet (unlike a later update, which is EnsureCanSetGroupTherapyPaymentGateAction's job).

function aPaymentGateGroupTherapyDTO(array $overrides = [])
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
        'paymentType' => TherapyPaymentTypeEnum::paid->value,
        'per' => 'PER_THERAPY',
        'amount' => 100,
        'currency' => 'GHS',
    ], $overrides));
}

test('creating a group therapy without strictPaymentGate defaults to false (trust-based), matching TT-7.5a', function () {
    $groupTherapy = CreateGroupTherapyAction::new()->execute(aPaymentGateGroupTherapyDTO());

    expect($groupTherapy->payment_data['strictPaymentGate'])->toBeFalse();
});

test('creating a group therapy without allowFreeHistoricalAccess defaults to true', function () {
    $groupTherapy = CreateGroupTherapyAction::new()->execute(aPaymentGateGroupTherapyDTO());

    expect($groupTherapy->payment_data['allowFreeHistoricalAccess'])->toBeTrue();
});

test('creating a group therapy with explicit strictPaymentGate/allowFreeHistoricalAccess honours the provided values', function () {
    $groupTherapy = CreateGroupTherapyAction::new()->execute(aPaymentGateGroupTherapyDTO([
        'strictPaymentGate' => true,
        'allowFreeHistoricalAccess' => false,
    ]));

    expect($groupTherapy->payment_data['strictPaymentGate'])->toBeTrue()
        ->and($groupTherapy->payment_data['allowFreeHistoricalAccess'])->toBeFalse();
});
