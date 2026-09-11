<?php

use App\Actions\GroupTherapy\UpdateGroupTherapyAction;
use App\DTOs\GroupTherapyDTO;
use App\Enums\TherapyPaymentTypeEnum;
use App\Models\GroupTherapy;
use App\Models\User;

// TT-7.5b-b1/SCRUM-265: strictPaymentGate/allowFreeHistoricalAccess on GroupTherapy update --
// mirrors UpdateTherapyAction's own identical treatment of strictPaymentGate exactly.

test('a partial update touching only strictPaymentGate leaves allowFreeHistoricalAccess and the rest of payment_data untouched', function () {
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => [
            'per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60,
            'strictPaymentGate' => false, 'allowFreeHistoricalAccess' => true,
        ],
    ]);

    $updated = UpdateGroupTherapyAction::new()->execute(GroupTherapyDTO::new()->fromArray([
        'groupTherapy' => $groupTherapy,
        'strictPaymentGate' => true,
    ]));

    expect($updated->payment_data)->toBe([
        'per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60,
        'strictPaymentGate' => true, 'allowFreeHistoricalAccess' => true,
    ]);
});

test('a partial update touching only allowFreeHistoricalAccess leaves strictPaymentGate and the rest of payment_data untouched', function () {
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => [
            'per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60,
            'strictPaymentGate' => true, 'allowFreeHistoricalAccess' => true,
        ],
    ]);

    $updated = UpdateGroupTherapyAction::new()->execute(GroupTherapyDTO::new()->fromArray([
        'groupTherapy' => $groupTherapy,
        'allowFreeHistoricalAccess' => false,
    ]));

    expect($updated->payment_data)->toBe([
        'per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60,
        'strictPaymentGate' => true, 'allowFreeHistoricalAccess' => false,
    ]);
});

// setValueOnPaymentData() writes the DTO's raw value verbatim -- confirms the explicit bool
// cast afterward actually runs, mirroring UpdateTherapyAction's own identical symmetry fix.
test('strictPaymentGate and allowFreeHistoricalAccess are force-cast to real booleans on update', function () {
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS'],
    ]);

    $updated = UpdateGroupTherapyAction::new()->execute(GroupTherapyDTO::new()->fromArray([
        'groupTherapy' => $groupTherapy,
        'strictPaymentGate' => true,
        'allowFreeHistoricalAccess' => false,
    ]));

    expect($updated->payment_data['strictPaymentGate'])->toBeTrue()
        ->and($updated->payment_data['allowFreeHistoricalAccess'])->toBeFalse();
});

// SCRUM-217-equivalent precedent: a group switched from FREE back to PAID must start with both
// settings at their normal defaults, not with either key simply absent.
test('switching a group therapy from FREE back to PAID resets strictPaymentGate/allowFreeHistoricalAccess to their defaults', function () {
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'payment_type' => TherapyPaymentTypeEnum::free->value,
        'payment_data' => null,
    ]);

    $updated = UpdateGroupTherapyAction::new()->execute(GroupTherapyDTO::new()->fromArray([
        'groupTherapy' => $groupTherapy,
        'paymentType' => TherapyPaymentTypeEnum::paid->value,
        'per' => 'PER_THERAPY',
        'amount' => 100,
        'currency' => 'GHS',
    ]));

    expect($updated->payment_data['strictPaymentGate'])->toBeFalse()
        ->and($updated->payment_data['allowFreeHistoricalAccess'])->toBeTrue();
});
