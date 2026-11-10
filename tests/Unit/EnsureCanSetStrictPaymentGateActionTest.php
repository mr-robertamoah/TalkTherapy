<?php

use App\Actions\Therapy\EnsureCanSetStrictPaymentGateAction;
use App\DTOs\CreateTherapyDTO;
use App\Exceptions\TherapyException;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\Therapy;
use App\Models\User;

// SCRUM-219/TT-7.5a security-review finding: strictPaymentGate must stay counsellor-controlled --
// the paying client must never be able to disable their own strict gate via updateTherapy.

test('the client cannot change strictPaymentGate on their own existing therapy', function () {
    $client = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
        'payment_type' => 'PAID',
        'payment_data' => ['strictPaymentGate' => true],
    ]);

    $dto = CreateTherapyDTO::new()->fromArray([
        'user' => $client,
        'therapy' => $therapy,
        'strictPaymentGate' => false,
    ]);

    expect(fn () => EnsureCanSetStrictPaymentGateAction::new()->execute($dto))
        ->toThrow(TherapyException::class);
});

test('the assigned counsellor can change strictPaymentGate', function () {
    $client = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
        'payment_type' => 'PAID',
        'payment_data' => ['strictPaymentGate' => true],
    ]);

    $dto = CreateTherapyDTO::new()->fromArray([
        'user' => $counsellorUser,
        'therapy' => $therapy,
        'strictPaymentGate' => false,
    ]);

    expect(fn () => EnsureCanSetStrictPaymentGateAction::new()->execute($dto))
        ->not->toThrow(TherapyException::class);
});

test('an admin can change strictPaymentGate', function () {
    $client = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'payment_type' => 'PAID',
        'payment_data' => ['strictPaymentGate' => true],
    ]);
    $admin = User::factory()->has(Administrator::factory())->create();

    $dto = CreateTherapyDTO::new()->fromArray([
        'user' => $admin,
        'therapy' => $therapy,
        'strictPaymentGate' => false,
    ]);

    expect(fn () => EnsureCanSetStrictPaymentGateAction::new()->execute($dto))
        ->not->toThrow(TherapyException::class);
});

test('the assigned counsellor resending the already-current value is still allowed (they are authorized regardless)', function () {
    $client = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
        'payment_type' => 'PAID',
        'payment_data' => ['strictPaymentGate' => true],
    ]);

    $dto = CreateTherapyDTO::new()->fromArray([
        'user' => $counsellorUser,
        'therapy' => $therapy,
        'strictPaymentGate' => true,
    ]);

    expect(fn () => EnsureCanSetStrictPaymentGateAction::new()->execute($dto))
        ->not->toThrow(TherapyException::class);
});

test('an unrelated user cannot "set" strictPaymentGate to its current, unchanged value either', function () {
    // Regression test (security-engineer finding): an earlier version returned early whenever
    // the submitted value matched the therapy's current one, BEFORE checking identity at all --
    // letting any authenticated caller "set" an arbitrary therapy's gate to its current value
    // and succeed. This is a boolean oracle (success vs. exception reveals the current value of
    // a therapy the caller has no relationship to) and must be blocked regardless of value.
    $client = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'payment_type' => 'PAID',
        'payment_data' => ['strictPaymentGate' => true],
    ]);
    $unrelatedUser = User::factory()->create();

    $dto = CreateTherapyDTO::new()->fromArray([
        'user' => $unrelatedUser,
        'therapy' => $therapy,
        'strictPaymentGate' => true, // the therapy's current, unchanged value
    ]);

    expect(fn () => EnsureCanSetStrictPaymentGateAction::new()->execute($dto))
        ->toThrow(TherapyException::class);
});

test('an update that does not mention strictPaymentGate at all is allowed for the client', function () {
    $client = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'payment_type' => 'PAID',
        'payment_data' => ['strictPaymentGate' => true],
    ]);

    $dto = CreateTherapyDTO::new()->fromArray([
        'user' => $client,
        'therapy' => $therapy,
        'name' => 'Renamed only',
    ]);

    expect(fn () => EnsureCanSetStrictPaymentGateAction::new()->execute($dto))
        ->not->toThrow(TherapyException::class);
});

test('the creating client can set strictPaymentGate at create time, when no therapy exists yet', function () {
    $client = User::factory()->create();

    $dto = CreateTherapyDTO::new()->fromArray([
        'user' => $client,
        'strictPaymentGate' => true,
    ]);

    expect(fn () => EnsureCanSetStrictPaymentGateAction::new()->execute($dto))
        ->not->toThrow(TherapyException::class);
});

test('the client cannot change strictPaymentGate before any counsellor is assigned', function () {
    $client = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => null,
        'payment_type' => 'PAID',
        'payment_data' => ['strictPaymentGate' => true],
    ]);

    $dto = CreateTherapyDTO::new()->fromArray([
        'user' => $client,
        'therapy' => $therapy,
        'strictPaymentGate' => false,
    ]);

    expect(fn () => EnsureCanSetStrictPaymentGateAction::new()->execute($dto))
        ->toThrow(TherapyException::class);
});
