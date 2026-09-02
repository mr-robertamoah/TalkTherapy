<?php

use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\Therapy;
use App\Models\User;

// SCRUM-221/TT-7.5a: regression test for a real bug found via live Playwright testing --
// EnsureCanUpdateTherapyAction has no branch for a counsellor merely ASSIGNED to a therapy via
// counsellor_id (only for one who happens to be the therapy's own addedby, a rare/different
// path), so the assigned counsellor could never reach therapies.update at all for an ordinarily
// client-created therapy. This dedicated endpoint bypasses that action entirely, relying solely
// on EnsureCanSetStrictPaymentGateAction's own, self-contained authorization.

function assignedCounsellorTherapy(): array
{
    $client = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
        'payment_type' => 'PAID',
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'strictPaymentGate' => false],
    ]);

    return [$client, $counsellorUser, $therapy];
}

test('the assigned counsellor (not the therapy addedby) can toggle strictPaymentGate via the dedicated endpoint', function () {
    [$client, $counsellorUser, $therapy] = assignedCounsellorTherapy();

    $response = $this->actingAs($counsellorUser)
        ->patch(route('therapies.strict_payment_gate.update', ['therapyId' => $therapy->id]), [
            'strictPaymentGate' => true,
        ]);

    $response->assertSessionHasNoErrors();
    expect($therapy->fresh()->payment_data['strictPaymentGate'])->toBeTrue();
});

test('the client is still refused on the dedicated endpoint, same as the general update endpoint', function () {
    [$client, $counsellorUser, $therapy] = assignedCounsellorTherapy();

    $response = $this->actingAs($client)
        ->patch(route('therapies.strict_payment_gate.update', ['therapyId' => $therapy->id]), [
            'strictPaymentGate' => true,
        ]);

    $response->assertSessionHasErrors('alert');
    expect($therapy->fresh()->payment_data['strictPaymentGate'])->toBeFalse();
});

test('an admin can toggle strictPaymentGate via the dedicated endpoint', function () {
    [$client, $counsellorUser, $therapy] = assignedCounsellorTherapy();
    $admin = User::factory()->has(Administrator::factory())->create();

    $response = $this->actingAs($admin)
        ->patch(route('therapies.strict_payment_gate.update', ['therapyId' => $therapy->id]), [
            'strictPaymentGate' => true,
        ]);

    $response->assertSessionHasNoErrors();
    expect($therapy->fresh()->payment_data['strictPaymentGate'])->toBeTrue();
});

test('an unrelated counsellor cannot toggle strictPaymentGate via the dedicated endpoint', function () {
    [$client, $counsellorUser, $therapy] = assignedCounsellorTherapy();
    $unrelatedCounsellorUser = User::factory()->create();
    Counsellor::factory()->create(['user_id' => $unrelatedCounsellorUser->id]);

    $response = $this->actingAs($unrelatedCounsellorUser)
        ->patch(route('therapies.strict_payment_gate.update', ['therapyId' => $therapy->id]), [
            'strictPaymentGate' => true,
        ]);

    $response->assertSessionHasErrors('alert');
    expect($therapy->fresh()->payment_data['strictPaymentGate'])->toBeFalse();
});

test('the strictPaymentGate field is required and must be boolean', function () {
    [$client, $counsellorUser, $therapy] = assignedCounsellorTherapy();

    $response = $this->actingAs($counsellorUser)
        ->patch(route('therapies.strict_payment_gate.update', ['therapyId' => $therapy->id]), []);

    $response->assertSessionHasErrors('strictPaymentGate');
});

test('an unrelated user cannot "set" strictPaymentGate to its current, unchanged value either', function () {
    // Regression test (security-engineer finding): the "unchanged value" short-circuit in
    // EnsureCanSetStrictPaymentGateAction originally ran BEFORE the identity check, so any
    // authenticated caller submitting the therapy's *current* value would slip through
    // unauthorized -- a boolean oracle (success vs. 422 reveals the current value of a therapy
    // the caller has no relationship to) plus an unauthorized write side-effect. The identity
    // check must run first regardless of whether the value is actually changing.
    [$client, $counsellorUser, $therapy] = assignedCounsellorTherapy();
    $unrelatedUser = User::factory()->create();

    $response = $this->actingAs($unrelatedUser)
        ->patch(route('therapies.strict_payment_gate.update', ['therapyId' => $therapy->id]), [
            'strictPaymentGate' => false, // the therapy's current, unchanged value
        ]);

    $response->assertSessionHasErrors('alert');
});
