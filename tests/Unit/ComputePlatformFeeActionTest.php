<?php

use App\Actions\Transaction\ComputePlatformFeeAction;

// TT-7.3b-b/SCRUM-233: extracted from GenerateCounsellorEarningsAction/ChargeOrganizationForModelAction
// (reviewer finding: previously duplicated verbatim) -- the ONE place the platform-fee percentage
// actually multiplies against a money amount, regardless of what that base amount represents.

test('computes a whole-number percentage fee', function () {
    config(['settings.platform_fee_percentage' => 10]);

    expect(ComputePlatformFeeAction::new()->execute(10000))->toBe(1000);
});

test('never drifts via float rounding for a fractional percentage/amount combination', function () {
    config(['settings.platform_fee_percentage' => 12.5]);

    // 12.5% of 10001 = 1250.125 -- must floor to an integer minor-unit amount, never a float.
    $fee = ComputePlatformFeeAction::new()->execute(10001);

    expect($fee)->toBeInt();
    expect($fee)->toBe(1250);
});

test('a zero base amount yields a zero fee', function () {
    config(['settings.platform_fee_percentage' => 10]);

    expect(ComputePlatformFeeAction::new()->execute(0))->toBe(0);
});

test('an absurdly large base amount is rejected rather than silently overflowing into a wrong float result', function () {
    config(['settings.platform_fee_percentage' => 100]);

    expect(fn () => ComputePlatformFeeAction::new()->execute(PHP_INT_MAX))
        ->toThrow(InvalidArgumentException::class);
});
