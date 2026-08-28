<?php

use App\Actions\Therapy\UpdateTherapyAction;
use App\DTOs\CreateTherapyDTO;
use App\Enums\TherapyPaymentTypeEnum;
use App\Models\Therapy;
use App\Models\User;

// Regression test for SCRUM-140: setValueOnPaymentData() wrote null over an already-set
// persisted payment_data value whenever a partial update omitted that field, since its
// condition treated "the persisted value exists and differs from the DTO's null" as a reason
// to write, rather than to leave unchanged -- silently nulling out an entire PAID therapy's
// pricing configuration on any partial edit that didn't resend every payment field.

test('a partial update touching only an unrelated field leaves an existing PAID therapy\'s payment_data untouched', function () {
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60],
    ]);

    $updated = UpdateTherapyAction::new()->execute(CreateTherapyDTO::new()->fromArray([
        'therapy' => $therapy,
        'name' => 'Renamed only',
    ]));

    expect($updated->name)->toBe('Renamed only');
    expect($updated->payment_data)->toBe(['per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60]);
});

test('a partial update explicitly changing only the amount leaves the other payment_data fields untouched', function () {
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60],
    ]);

    $updated = UpdateTherapyAction::new()->execute(CreateTherapyDTO::new()->fromArray([
        'therapy' => $therapy,
        'amount' => 75.0,
    ]));

    // amount round-trips through the payment_data array cast (JSON under the hood), which
    // collapses a whole-number float like 75.0 to an int -- unrelated to the fix under test.
    expect($updated->payment_data)->toBe(['per' => 'PER_THERAPY', 'amount' => 75, 'currency' => 'GHS', 'inPersonAmount' => 60]);
});
