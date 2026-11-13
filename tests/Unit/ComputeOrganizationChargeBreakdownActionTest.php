<?php

use App\Actions\Organization\ComputeOrganizationChargeBreakdownAction;
use App\Enums\OrganizationCounsellorCompensationBasisEnum;
use App\Enums\OrganizationCounsellorCompensationTypeEnum;
use App\Models\Counsellor;
use App\Models\OrganizationCounsellorCompensation;
use App\Models\User;

// SCRUM-420: the one place an organisation's bill for one member's place is worked out (card charge and retainer).

function cobCounsellors(int $count)
{
    return collect(range(1, $count))->map(fn () => Counsellor::factory()->create(['user_id' => User::factory()]));
}

function cobTerms(array $attributes): OrganizationCounsellorCompensation
{
    return new OrganizationCounsellorCompensation($attributes);
}

function cobBreakdown($counsellors, array $termsById, ?int $listed, array $seat = [0, 1]): array
{
    config(['settings.platform_fee_percentage' => 10]);

    return ComputeOrganizationChargeBreakdownAction::new()->execute($counsellors, fn (Counsellor $counsellor) => $termsById[$counsellor->id] ?? null, $listed, $seat);
}

test('a price that doesn\'t divide evenly gives the first counsellor the remainder, and the fee splits the same way', function () {
    $counsellors = cobCounsellors(3);
    $full = cobTerms(['type' => OrganizationCounsellorCompensationTypeEnum::percentage->value, 'basis' => OrganizationCounsellorCompensationBasisEnum::counsellorRate->value, 'percentage' => 100]);

    // 10001 in three: 3335, 3333, 3333; fee 10% of 10001 = 1000 in three: 334, 333, 333.
    $breakdown = cobBreakdown($counsellors, $counsellors->mapWithKeys(fn ($c) => [$c->id => $full])->all(), 10001);

    expect(array_column($breakdown['lines'], 'net'))->toBe([3335, 3333, 3333])
        ->and(array_column($breakdown['lines'], 'fee'))->toBe([334, 333, 333])
        ->and($breakdown['skipped'])->toBe([]);
});

test('a percentage of a negotiated rate is for the whole unit, shared by place', function () {
    $counsellors = cobCounsellors(1);
    $negotiated = cobTerms(['type' => OrganizationCounsellorCompensationTypeEnum::percentage->value, 'basis' => OrganizationCounsellorCompensationBasisEnum::negotiatedRate->value, 'percentage' => 50, 'negotiated_rate_amount' => 10000]);

    // 50% of 10000 = 5000, over 4 places: 1250 for the third place.
    $breakdown = cobBreakdown($counsellors, [$counsellors[0]->id => $negotiated], 20000, [2, 4]);

    expect($breakdown['lines'][0]['net'])->toBe(1250)
        ->and($breakdown['lines'][0]['fee'])->toBe(2000);
});

test('a counsellor with no terms is skipped, and takes their part\'s fee with them', function () {
    $counsellors = cobCounsellors(2);
    $free = cobTerms(['type' => OrganizationCounsellorCompensationTypeEnum::free->value]);

    $breakdown = cobBreakdown($counsellors, [$counsellors[0]->id => $free], 10000);

    expect($breakdown['lines'])->toHaveCount(1)
        ->and($breakdown['lines'][0]['fee'])->toBe(500)
        ->and($breakdown['skipped'][0]['reason'])->toBe('no_terms');
});

test('a share of the counsellor\'s rate with no listed price can\'t be computed', function () {
    $counsellors = cobCounsellors(1);
    $rate = cobTerms(['type' => OrganizationCounsellorCompensationTypeEnum::percentage->value, 'basis' => OrganizationCounsellorCompensationBasisEnum::counsellorRate->value, 'percentage' => 80]);

    $breakdown = cobBreakdown($counsellors, [$counsellors[0]->id => $rate], null);

    expect($breakdown['lines'])->toBe([])
        ->and($breakdown['skipped'][0]['reason'])->toBe('unable_to_compute');
});

test('a place outside the unit\'s places is refused, never billed at zero', function () {
    $counsellors = cobCounsellors(1);
    $fixed = cobTerms(['type' => OrganizationCounsellorCompensationTypeEnum::fixed->value, 'amount' => 3000]);

    $breakdown = cobBreakdown($counsellors, [$counsellors[0]->id => $fixed], 10000, [5, 3]);

    expect($breakdown['lines'])->toBe([])
        ->and($breakdown['skipped'][0]['reason'])->toBe('unable_to_compute');
});
