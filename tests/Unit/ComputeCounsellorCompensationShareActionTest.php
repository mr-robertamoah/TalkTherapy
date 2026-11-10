<?php

use App\Actions\Organization\ComputeCounsellorCompensationShareAction;
use App\Enums\OrganizationCounsellorCompensationBasisEnum;
use App\Enums\OrganizationCounsellorCompensationTypeEnum;
use App\Models\Counsellor;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\OrganizationCounsellorCompensation;
use App\Models\User;

// TT-7.3b-b0/SCRUM-232: the one place a counsellor's compensation-driven share of a session gets
// computed -- consumed (not yet, future tickets) by TT-7.3b-b/-d. All money is minor units.

function aCompensation(array $overrides = []): OrganizationCounsellorCompensation
{
    $affiliation = OrganizationCounsellor::factory()->create([
        'organization_id' => Organization::factory(),
        'counsellor_id' => Counsellor::factory()->create(['user_id' => User::factory()])->id,
    ]);

    return OrganizationCounsellorCompensation::factory()->create(array_merge([
        'organization_counsellor_id' => $affiliation->id,
    ], $overrides));
}

test('FREE compensation always yields a zero share', function () {
    $compensation = aCompensation(['type' => OrganizationCounsellorCompensationTypeEnum::free->value]);

    expect(ComputeCounsellorCompensationShareAction::new()->execute($compensation))->toBe(0);
});

test('FIXED compensation yields its own flat amount, independent of any listed rate', function () {
    $compensation = aCompensation([
        'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
        'amount' => 5000,
        'currency' => 'GHS',
    ]);

    expect(ComputeCounsellorCompensationShareAction::new()->execute($compensation, 999999))->toBe(5000);
});

test('PERCENTAGE with basis COUNSELLOR_RATE takes a percentage of the supplied listed amount', function () {
    $compensation = aCompensation([
        'type' => OrganizationCounsellorCompensationTypeEnum::percentage->value,
        'percentage' => 70,
        'basis' => OrganizationCounsellorCompensationBasisEnum::counsellorRate->value,
    ]);

    expect(ComputeCounsellorCompensationShareAction::new()->execute($compensation, 10000))->toBe(7000);
});

test('PERCENTAGE with basis COUNSELLOR_RATE throws without a supplied listed amount', function () {
    $compensation = aCompensation([
        'type' => OrganizationCounsellorCompensationTypeEnum::percentage->value,
        'percentage' => 70,
        'basis' => OrganizationCounsellorCompensationBasisEnum::counsellorRate->value,
    ]);

    expect(fn () => ComputeCounsellorCompensationShareAction::new()->execute($compensation))
        ->toThrow(InvalidArgumentException::class);
});

test('PERCENTAGE with basis NEGOTIATED_RATE takes a percentage of the stored negotiated rate, ignoring any supplied listed amount', function () {
    $compensation = aCompensation([
        'type' => OrganizationCounsellorCompensationTypeEnum::percentage->value,
        'percentage' => 70,
        'basis' => OrganizationCounsellorCompensationBasisEnum::negotiatedRate->value,
        'negotiated_rate_amount' => 25000,
    ]);

    expect(ComputeCounsellorCompensationShareAction::new()->execute($compensation, 999999))->toBe(17500);
});

test('PERCENTAGE with basis NEGOTIATED_RATE throws if no negotiated rate amount was recorded', function () {
    $compensation = aCompensation([
        'type' => OrganizationCounsellorCompensationTypeEnum::percentage->value,
        'percentage' => 70,
        'basis' => OrganizationCounsellorCompensationBasisEnum::negotiatedRate->value,
        'negotiated_rate_amount' => null,
    ]);

    expect(fn () => ComputeCounsellorCompensationShareAction::new()->execute($compensation))
        ->toThrow(InvalidArgumentException::class);
});

test('percentage math never drifts via float rounding for an odd percentage/amount combination', function () {
    $compensation = aCompensation([
        'type' => OrganizationCounsellorCompensationTypeEnum::percentage->value,
        'percentage' => 33,
        'basis' => OrganizationCounsellorCompensationBasisEnum::counsellorRate->value,
    ]);

    // 33% of 10,001 = 3,300.33 -- must floor to an integer minor-unit amount, never a float.
    $share = ComputeCounsellorCompensationShareAction::new()->execute($compensation, 10001);

    expect($share)->toBeInt();
    expect($share)->toBe(3300);
});

test('an absurdly large basis amount is rejected rather than silently overflowing into a wrong float result', function () {
    $compensation = aCompensation([
        'type' => OrganizationCounsellorCompensationTypeEnum::percentage->value,
        'percentage' => 100,
        'basis' => OrganizationCounsellorCompensationBasisEnum::negotiatedRate->value,
        'negotiated_rate_amount' => PHP_INT_MAX,
    ]);

    expect(fn () => ComputeCounsellorCompensationShareAction::new()->execute($compensation))
        ->toThrow(InvalidArgumentException::class);
});
