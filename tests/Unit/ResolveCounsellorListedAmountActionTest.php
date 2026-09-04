<?php

use App\Actions\Transaction\ResolveCounsellorListedAmountAction;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;

// TT-7.3b-d/SCRUM-235 (reviewer finding): extracted from a byte-for-byte duplicate between
// ChargeOrganizationForModelAction and GenerateCounsellorEarningsAction.

test('converts a therapy\'s listed major-unit amount to minor units', function () {
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 150, 'currency' => 'GHS'],
    ]);

    expect(ResolveCounsellorListedAmountAction::new()->execute($therapy))->toBe(15000);
});

test('resolves a session through its parent therapy\'s listed amount', function () {
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'payment_data' => ['per' => 'PER_SESSION', 'amount' => 50, 'currency' => 'GHS'],
    ]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);

    expect(ResolveCounsellorListedAmountAction::new()->execute($session))->toBe(5000);
});

test('returns null when no amount is set', function () {
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'payment_data' => null,
    ]);

    expect(ResolveCounsellorListedAmountAction::new()->execute($therapy))->toBeNull();
});
