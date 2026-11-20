<?php

use App\Actions\Organization\ComputeOrganizationChargeBreakdownAction;
use App\Actions\Transaction\ResolvePlatformFeePolicyAction;
use App\Enums\OrganizationCounsellorCompensationBasisEnum;
use App\Enums\OrganizationCounsellorCompensationTypeEnum;
use App\Models\Counsellor;
use App\Models\OrganizationCounsellorCompensation;
use App\Models\PlatformFeeWaiver;
use App\Models\User;
use App\Support\PlatformFeePolicy;
use Illuminate\Support\Carbon;

// TT-7.10a/SCRUM-467: the platform fee as it stood at a moment -- the percentage and the fee-free periods then.

function pfpCounsellor(): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()]);
}

function pfpPolicy(Carbon|string $at = 'now'): PlatformFeePolicy
{
    config(['settings.platform_fee_percentage' => 10]);

    return ResolvePlatformFeePolicyAction::new()->execute($at instanceof Carbon ? $at : Carbon::parse($at));
}

function pfpBreakdown($counsellors, ?int $listed, PlatformFeePolicy $fees): array
{
    $terms = new OrganizationCounsellorCompensation([
        'type' => OrganizationCounsellorCompensationTypeEnum::percentage->value,
        'basis' => OrganizationCounsellorCompensationBasisEnum::counsellorRate->value,
        'percentage' => 80,
    ]);

    return ComputeOrganizationChargeBreakdownAction::new()->execute($counsellors, fn () => $terms, $listed, [0, 1], $fees);
}

arch('the fee arithmetic is only reached through the fee policy')
    ->expect('App\Actions\Transaction\ComputePlatformFeeAction')
    ->toOnlyBeUsedIn(['App\Support\PlatformFeePolicy']);

test('with no waiver the fee is today\'s fee', function () {
    expect(pfpPolicy()->feeFor(10001, pfpCounsellor()))->toBe(['fee' => 1000, 'waiverId' => null, 'waived' => 0]);
});

test('a platform-wide waiver takes the whole fee off, and records what it was', function () {
    $waiver = PlatformFeeWaiver::factory()->create();

    expect(pfpPolicy()->feeFor(10000, pfpCounsellor()))->toBe(['fee' => 0, 'waiverId' => $waiver->id, 'waived' => 1000])
        ->and(pfpPolicy()->feeFor(10000))->toBe(['fee' => 0, 'waiverId' => $waiver->id, 'waived' => 1000]);
});

test('a counsellor\'s waiver covers only that counsellor', function () {
    $covered = pfpCounsellor();
    $waiver = PlatformFeeWaiver::factory()->forCounsellor($covered)->create();
    $policy = pfpPolicy();

    expect($policy->feeFor(10000, $covered))->toBe(['fee' => 0, 'waiverId' => $waiver->id, 'waived' => 1000])
        ->and($policy->feeFor(10000, pfpCounsellor()))->toBe(['fee' => 1000, 'waiverId' => null, 'waived' => 0])
        ->and($policy->feeFor(10000))->toBe(['fee' => 1000, 'waiverId' => null, 'waived' => 0]);
});

test('a platform-wide waiver is the one recorded when a counsellor\'s also covers the moment', function () {
    $counsellor = pfpCounsellor();
    PlatformFeeWaiver::factory()->forCounsellor($counsellor)->create();
    $platform = PlatformFeeWaiver::factory()->create();

    expect(pfpPolicy()->feeFor(10000, $counsellor)['waiverId'])->toBe($platform->id);
});

test('the period starts at starts_at and ends just before ends_at', function () {
    $startsAt = Carbon::parse('2026-12-01 00:00:00');
    $endsAt = Carbon::parse('2026-12-08 00:00:00');
    PlatformFeeWaiver::factory()->between($startsAt, $endsAt)->create();

    expect(pfpPolicy($startsAt->copy()->subSecond())->feeFor(10000)['fee'])->toBe(1000)
        ->and(pfpPolicy($startsAt)->feeFor(10000)['fee'])->toBe(0)
        ->and(pfpPolicy($endsAt->copy()->subSecond())->feeFor(10000)['fee'])->toBe(0)
        ->and(pfpPolicy($endsAt)->feeFor(10000)['fee'])->toBe(1000);
});

test('an open-ended period has no end', function () {
    PlatformFeeWaiver::factory()->between(Carbon::parse('2026-12-01'), null)->create();

    expect(pfpPolicy('2036-12-01')->feeFor(10000)['fee'])->toBe(0);
});

test('a waiver is recorded only when there was a fee to waive', function () {
    PlatformFeeWaiver::factory()->create();

    expect(pfpPolicy()->feeFor(0))->toBe(['fee' => 0, 'waiverId' => null, 'waived' => 0])
        ->and(pfpPolicy()->feeFor(9))->toBe(['fee' => 0, 'waiverId' => null, 'waived' => 0]);
});

test('an ended or upcoming period waives nothing now', function () {
    PlatformFeeWaiver::factory()->ended()->create();
    PlatformFeeWaiver::factory()->upcoming()->create();

    expect(pfpPolicy()->feeFor(10000))->toBe(['fee' => 1000, 'waiverId' => null, 'waived' => 0]);
});

test('the breakdown with a platform-wide waiver: no fee on any line, each records its own part\'s fee', function () {
    $counsellors = collect([pfpCounsellor(), pfpCounsellor(), pfpCounsellor()]);
    $waiver = PlatformFeeWaiver::factory()->create();

    // 10001 in three: 3335, 3333, 3333 -- 80% each; 10% of each part waived.
    $lines = pfpBreakdown($counsellors, 10001, pfpPolicy())['lines'];

    expect(array_column($lines, 'net'))->toBe([2668, 2666, 2666])
        ->and(array_column($lines, 'fee'))->toBe([0, 0, 0])
        ->and(array_column($lines, 'platform_fee_waiver_id'))->toBe([$waiver->id, $waiver->id, $waiver->id])
        ->and(array_column($lines, 'waived_fee_amount'))->toBe([333, 333, 333]);
});

test('the breakdown with one counsellor waived: the others share the fee on their own parts, as before', function () {
    $counsellors = collect([pfpCounsellor(), pfpCounsellor(), pfpCounsellor()]);
    $waiver = PlatformFeeWaiver::factory()->forCounsellor($counsellors[0])->create();

    // Parts 3335, 3333, 3333. The waived first line's fee: 10% of 3335 = 333. The others' fee: 10% of 6666 = 666,
    // split 333/333.
    $lines = pfpBreakdown($counsellors, 10001, pfpPolicy())['lines'];

    expect(array_column($lines, 'fee'))->toBe([0, 333, 333])
        ->and(array_column($lines, 'platform_fee_waiver_id'))->toBe([$waiver->id, null, null])
        ->and(array_column($lines, 'waived_fee_amount'))->toBe([333, 0, 0]);
});

test('the breakdown with no waiver is what it always was, and every line says so', function () {
    $counsellors = collect([pfpCounsellor(), pfpCounsellor(), pfpCounsellor()]);
    PlatformFeeWaiver::factory()->ended()->create();

    $lines = pfpBreakdown($counsellors, 10001, pfpPolicy())['lines'];

    expect(array_column($lines, 'fee'))->toBe([334, 333, 333])
        ->and(array_column($lines, 'platform_fee_waiver_id'))->toBe([null, null, null])
        ->and(array_column($lines, 'waived_fee_amount'))->toBe([0, 0, 0]);
});

test('the breakdown\'s fee taken plus fee waived is never more than the fee on the whole price', function () {
    $counsellors = collect([pfpCounsellor(), pfpCounsellor()]);
    PlatformFeeWaiver::factory()->forCounsellor($counsellors[1])->create();

    $lines = pfpBreakdown($counsellors, 9999, pfpPolicy())['lines'];
    $whole = pfpPolicy()->feeFor(9999)['fee'];

    expect(array_sum(array_column($lines, 'fee')) + array_sum(array_column($lines, 'waived_fee_amount')))->toBeLessThanOrEqual($whole)
        ->and(array_sum(array_column($lines, 'fee')) + array_sum(array_column($lines, 'waived_fee_amount')))->toBeGreaterThanOrEqual($whole - count($lines));
});

test('a breakdown with no listed price records no waiver -- there was no fee to waive', function () {
    $counsellors = collect([pfpCounsellor()]);
    PlatformFeeWaiver::factory()->create();
    $fixed = new OrganizationCounsellorCompensation(['type' => OrganizationCounsellorCompensationTypeEnum::fixed->value, 'amount' => 3000]);

    $lines = ComputeOrganizationChargeBreakdownAction::new()->execute($counsellors, fn () => $fixed, null, [0, 1], pfpPolicy())['lines'];

    expect($lines[0]['fee'])->toBe(0)
        ->and($lines[0]['waived_fee_amount'])->toBe(0)
        ->and($lines[0]['platform_fee_waiver_id'])->toBeNull();
});
