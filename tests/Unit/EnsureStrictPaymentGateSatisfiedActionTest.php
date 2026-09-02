<?php

use App\Actions\Transaction\EnsureStrictPaymentGateSatisfiedAction;
use App\Exceptions\PaymentRequiredException;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;

// SCRUM-220/TT-7.5a: direct unit coverage of the shared action's own contract, independent of
// any one caller's wiring -- pins down all 4 per/session-presence combinations so a future
// caller can't silently reintroduce the exact "PER_THERAPY not gated when a $session happens to
// be passed" bug this ticket fixed (see MessageServiceStrictPaymentGateTest and
// EnsureUserHasAccessToTherapyActionStrictPaymentGateTest for the same matrix exercised through
// real call sites).

function gateTherapy(string $per): Therapy
{
    return Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'payment_type' => 'PAID',
        'payment_data' => ['per' => $per, 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60, 'strictPaymentGate' => true],
    ]);
}

test('PER_THERAPY with no session in context gates the therapy', function () {
    $user = User::factory()->create();
    $therapy = gateTherapy('PER_THERAPY');

    expect(fn () => EnsureStrictPaymentGateSatisfiedAction::new()->execute($therapy, $user))
        ->toThrow(PaymentRequiredException::class);
});

test('PER_THERAPY with a session in context still gates the therapy, not the session', function () {
    $user = User::factory()->create();
    $therapy = gateTherapy('PER_THERAPY');
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);

    expect(fn () => EnsureStrictPaymentGateSatisfiedAction::new()->execute($therapy, $user, $session))
        ->toThrow(PaymentRequiredException::class);

    $this->assertDatabaseMissing('payment_access_grants', [
        'for_type' => Session::class,
        'for_id' => $session->id,
    ]);
});

test('PER_SESSION with no session in context has nothing to gate', function () {
    $user = User::factory()->create();
    $therapy = gateTherapy('PER_SESSION');

    expect(fn () => EnsureStrictPaymentGateSatisfiedAction::new()->execute($therapy, $user))
        ->not->toThrow(PaymentRequiredException::class);

    $this->assertDatabaseCount('payment_access_grants', 0);
});

test('PER_SESSION with a session in context gates that session', function () {
    $user = User::factory()->create();
    $therapy = gateTherapy('PER_SESSION');
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);

    expect(fn () => EnsureStrictPaymentGateSatisfiedAction::new()->execute($therapy, $user, $session))
        ->toThrow(PaymentRequiredException::class);
});
