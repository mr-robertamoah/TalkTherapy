<?php

use App\Actions\Therapy\CreateTherapyAction;
use App\Actions\Therapy\UpdateTherapyAction;
use App\DTOs\CreateTherapyDTO;
use App\Enums\TherapyPaymentTypeEnum;
use App\Models\Therapy;
use App\Models\User;

// SCRUM-217/TT-7.5a: per-Therapy strict/trust payment-gate setting.

test('creating a therapy without specifying strictPaymentGate defaults to false (trust-based)', function () {
    $user = User::factory()->create();

    $therapy = CreateTherapyAction::new()->execute(CreateTherapyDTO::new()->fromArray([
        'user' => $user,
        'name' => 'Trust-based by default',
        'backgroundStory' => 'Test background story.',
        'public' => true,
        'maxSessions' => 1,
        'sessionType' => 'ONCE',
        'anonymous' => false,
        'allowInPerson' => false,
        'paymentType' => TherapyPaymentTypeEnum::paid->value,
        'per' => 'PER_THERAPY',
        'amount' => 50,
        'currency' => 'GHS',
    ]));

    expect($therapy->payment_data['strictPaymentGate'])->toBeFalse();
    expect($therapy->strictPaymentGate)->toBeFalse();
});

test('creating a therapy with strictPaymentGate explicitly true persists it', function () {
    $user = User::factory()->create();

    $therapy = CreateTherapyAction::new()->execute(CreateTherapyDTO::new()->fromArray([
        'user' => $user,
        'name' => 'Strict from the start',
        'backgroundStory' => 'Test background story.',
        'public' => true,
        'maxSessions' => 1,
        'sessionType' => 'ONCE',
        'anonymous' => false,
        'allowInPerson' => false,
        'paymentType' => TherapyPaymentTypeEnum::paid->value,
        'per' => 'PER_THERAPY',
        'amount' => 50,
        'currency' => 'GHS',
        'strictPaymentGate' => true,
    ]));

    expect($therapy->payment_data['strictPaymentGate'])->toBeTrue();
    expect($therapy->strictPaymentGate)->toBeTrue();
});

test('a partial update omitting strictPaymentGate leaves an existing therapy\'s setting unchanged', function () {
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60, 'strictPaymentGate' => true],
    ]);

    $updated = UpdateTherapyAction::new()->execute(CreateTherapyDTO::new()->fromArray([
        'therapy' => $therapy,
        'name' => 'Renamed only',
    ]));

    expect($updated->strictPaymentGate)->toBeTrue();
    expect($updated->payment_data['strictPaymentGate'])->toBeTrue();
});

test('an update explicitly toggling strictPaymentGate changes only that setting', function () {
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60, 'strictPaymentGate' => false],
    ]);

    $updated = UpdateTherapyAction::new()->execute(CreateTherapyDTO::new()->fromArray([
        'therapy' => $therapy,
        'strictPaymentGate' => true,
    ]));

    expect($updated->payment_data)->toBe([
        'per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60, 'strictPaymentGate' => true,
    ]);
});

test('switching payment type to free clears strictPaymentGate along with the rest of payment_data', function () {
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'public' => true,
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60, 'strictPaymentGate' => true],
    ]);

    $updated = UpdateTherapyAction::new()->execute(CreateTherapyDTO::new()->fromArray([
        'therapy' => $therapy,
        'paymentType' => TherapyPaymentTypeEnum::free->value,
    ]));

    expect($updated->payment_data)->toBeNull();
    expect($updated->strictPaymentGate)->toBeFalse();
});

test('the strictPaymentGate accessor defaults to false for a therapy predating this feature', function () {
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        // No strictPaymentGate key at all -- simulates a row written before this feature existed.
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60],
    ]);

    expect($therapy->strictPaymentGate)->toBeFalse();
});

test('the strictPaymentGate accessor defaults to false when payment_data is entirely null', function () {
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'payment_type' => TherapyPaymentTypeEnum::free->value,
        'payment_data' => null,
    ]);

    expect($therapy->strictPaymentGate)->toBeFalse();
});
