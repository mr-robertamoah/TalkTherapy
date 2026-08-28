<?php

use App\Actions\GroupTherapy\UpdateGroupTherapyAction;
use App\DTOs\GroupTherapyDTO;
use App\Enums\TherapyPaymentTypeEnum;
use App\Models\GroupTherapy;
use App\Models\User;

// Regression test for SCRUM-140: same bug as UpdateTherapyActionPaymentDataTest, in the
// GroupTherapy-specific sibling action -- also nulls out shareEqually/sharePercentage, which
// have no equivalent field in the individual-Therapy version.

test('a partial update touching only an unrelated field leaves an existing PAID group therapy\'s payment_data untouched', function () {
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => [
            'per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60,
            'shareEqually' => false, 'sharePercentage' => 70,
        ],
    ]);

    $updated = UpdateGroupTherapyAction::new()->execute(GroupTherapyDTO::new()->fromArray([
        'groupTherapy' => $groupTherapy,
        'name' => 'Renamed only',
    ]));

    expect($updated->name)->toBe('Renamed only');
    expect($updated->payment_data)->toBe([
        'per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60,
        'shareEqually' => false, 'sharePercentage' => 70,
    ]);
});

test('a partial update explicitly changing only sharePercentage leaves the other payment_data fields untouched', function () {
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => [
            'per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60,
            'shareEqually' => false, 'sharePercentage' => 70,
        ],
    ]);

    $updated = UpdateGroupTherapyAction::new()->execute(GroupTherapyDTO::new()->fromArray([
        'groupTherapy' => $groupTherapy,
        'sharePercentage' => 85,
    ]));

    expect($updated->payment_data)->toBe([
        'per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60,
        'shareEqually' => false, 'sharePercentage' => 85,
    ]);
});
