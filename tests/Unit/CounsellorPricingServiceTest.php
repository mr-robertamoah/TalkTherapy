<?php

use App\Actions\Transaction\GetPayableAmountAction;
use App\DTOs\CounsellorPricingDTO;
use App\Enums\SessionTypeEnum;
use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapyPerPaymentEnum;
use App\Enums\TherapySessionTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Enums\TherapyTypeEnum;
use App\Exceptions\CounsellorException;
use App\Exceptions\CounsellorNotFoundException;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\CounsellorPricing;
use App\Models\Therapy;
use App\Models\User;
use App\Services\CounsellorPricingService;

// SCRUM-154 (TT-7.2b): counsellor pricing is strictly informational -- these tests, plus the
// dedicated regression test at the bottom, confirm the mode rules (flat XOR override, atomic
// replace) and prove the actual charge pipeline never reads from it.

function aCounsellorForPricing(): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()]);
}

function setPricing(Counsellor $counsellor, array $pricings, ?User $actingUser = null)
{
    return CounsellorPricingService::new()->setPricing(
        CounsellorPricingDTO::new()->fromArray([
            'user' => $actingUser ?: $counsellor->user,
            'counsellor' => $counsellor,
            'pricings' => $pricings,
        ])
    );
}

test('a counsellor can set a single flat rate', function () {
    $counsellor = aCounsellorForPricing();

    $result = setPricing($counsellor, [
        ['amount' => 150, 'currency' => 'GHS'],
    ]);

    expect($result)->toHaveCount(1);
    expect($result->first()->isFlat())->toBeTrue();
    expect($result->first()->amount)->toBe(150);
});

test('a counsellor can set distinct override rates for specific, fully-specified combinations', function () {
    $counsellor = aCounsellorForPricing();

    $result = setPricing($counsellor, [
        ['therapyType' => TherapyTypeEnum::individual->value, 'sessionType' => SessionTypeEnum::online->value, 'per' => TherapyPerPaymentEnum::session->value, 'amount' => 100, 'currency' => 'GHS'],
        ['therapyType' => TherapyTypeEnum::individual->value, 'sessionType' => SessionTypeEnum::in_person->value, 'per' => TherapyPerPaymentEnum::session->value, 'amount' => 200, 'currency' => 'GHS'],
    ]);

    expect($result)->toHaveCount(2);
    expect($result->every(fn ($pricing) => ! $pricing->isFlat()))->toBeTrue();
});

test('setting a new pricing configuration atomically replaces the previous one', function () {
    $counsellor = aCounsellorForPricing();

    setPricing($counsellor, [['amount' => 150, 'currency' => 'GHS']]);
    expect(CounsellorPricing::query()->where('counsellor_id', $counsellor->id)->count())->toBe(1);

    setPricing($counsellor, [
        ['therapyType' => TherapyTypeEnum::group->value, 'sessionType' => SessionTypeEnum::online->value, 'per' => TherapyPerPaymentEnum::therapy->value, 'amount' => 300, 'currency' => 'GHS'],
    ]);

    $remaining = CounsellorPricing::query()->where('counsellor_id', $counsellor->id)->get();
    expect($remaining)->toHaveCount(1);
    expect($remaining->first()->isFlat())->toBeFalse();
});

test('a flat rate cannot be mixed with scoped overrides in the same save', function () {
    $counsellor = aCounsellorForPricing();

    expect(fn () => setPricing($counsellor, [
        ['amount' => 150, 'currency' => 'GHS'],
        ['therapyType' => TherapyTypeEnum::individual->value, 'sessionType' => SessionTypeEnum::online->value, 'per' => TherapyPerPaymentEnum::session->value, 'amount' => 100, 'currency' => 'GHS'],
    ]))->toThrow(CounsellorException::class, 'Pricing cannot mix a flat rate with scoped overrides.');
});

test('more than one flat rate is rejected', function () {
    $counsellor = aCounsellorForPricing();

    expect(fn () => setPricing($counsellor, [
        ['amount' => 150, 'currency' => 'GHS'],
        ['amount' => 200, 'currency' => 'GHS'],
    ]))->toThrow(CounsellorException::class, 'Only one flat rate is allowed at a time.');
});

test('a partially-scoped override is rejected', function () {
    $counsellor = aCounsellorForPricing();

    expect(fn () => setPricing($counsellor, [
        ['therapyType' => TherapyTypeEnum::individual->value, 'amount' => 100, 'currency' => 'GHS'],
    ]))->toThrow(CounsellorException::class, 'Each pricing override must specify a therapy type, session type, and per.');
});

test('two overrides covering the same exact scope are rejected', function () {
    $counsellor = aCounsellorForPricing();

    expect(fn () => setPricing($counsellor, [
        ['therapyType' => TherapyTypeEnum::individual->value, 'sessionType' => SessionTypeEnum::online->value, 'per' => TherapyPerPaymentEnum::session->value, 'amount' => 100, 'currency' => 'GHS'],
        ['therapyType' => TherapyTypeEnum::individual->value, 'sessionType' => SessionTypeEnum::online->value, 'per' => TherapyPerPaymentEnum::session->value, 'amount' => 120, 'currency' => 'GHS'],
    ]))->toThrow(CounsellorException::class, 'Pricing overrides cannot repeat the same therapy type, session type, and per combination.');
});

test('a pricing entry missing an amount or currency is rejected', function () {
    $counsellor = aCounsellorForPricing();

    expect(fn () => setPricing($counsellor, [
        ['amount' => 150],
    ]))->toThrow(CounsellorException::class, 'Every pricing entry requires both an amount and a currency.');
});

test('a counsellor cannot set pricing for another counsellor', function () {
    $counsellor = aCounsellorForPricing();
    $otherCounsellor = aCounsellorForPricing();

    expect(fn () => setPricing($counsellor, [['amount' => 150, 'currency' => 'GHS']], $otherCounsellor->user))
        ->toThrow(CounsellorException::class, 'You are not authorized to set this pricing.');
});

test('a platform admin can set pricing on behalf of a counsellor', function () {
    $counsellor = aCounsellorForPricing();
    $admin = User::factory()->has(Administrator::factory())->create();

    $result = setPricing($counsellor, [['amount' => 150, 'currency' => 'GHS']], $admin);

    expect($result)->toHaveCount(1);
});

// SCRUM-155 (TT-7.2c): clearing pricing entirely -- discovered as a gap while building the UI,
// since SetCounsellorPricingAction always requires at least one entry.
test('a counsellor can clear their pricing entirely', function () {
    $counsellor = aCounsellorForPricing();
    setPricing($counsellor, [['amount' => 150, 'currency' => 'GHS']]);
    expect(CounsellorPricing::query()->where('counsellor_id', $counsellor->id)->count())->toBe(1);

    CounsellorPricingService::new()->clearPricing(
        CounsellorPricingDTO::new()->fromArray(['user' => $counsellor->user, 'counsellor' => $counsellor])
    );

    expect(CounsellorPricing::query()->where('counsellor_id', $counsellor->id)->count())->toBe(0);
});

test('a counsellor cannot clear another counsellor\'s pricing', function () {
    $counsellor = aCounsellorForPricing();
    $otherCounsellor = aCounsellorForPricing();
    setPricing($counsellor, [['amount' => 150, 'currency' => 'GHS']]);

    expect(fn () => CounsellorPricingService::new()->clearPricing(
        CounsellorPricingDTO::new()->fromArray(['user' => $otherCounsellor->user, 'counsellor' => $counsellor])
    ))->toThrow(CounsellorException::class, 'You are not authorized to set this pricing.');

    expect(CounsellorPricing::query()->where('counsellor_id', $counsellor->id)->count())->toBe(1);
});

test('setting pricing for a counsellor that does not exist is rejected', function () {
    expect(fn () => CounsellorPricingService::new()->setPricing(
        CounsellorPricingDTO::new()->fromArray([
            'user' => User::factory()->create(),
            'counsellor' => null,
            'pricings' => [['amount' => 150, 'currency' => 'GHS']],
        ])
    ))->toThrow(CounsellorNotFoundException::class);
});

// AC #7: no code path in app/Actions/Transaction/ or GetPayableAmountAction reads from
// counsellor_pricings -- a listed rate must have zero effect on what a client is actually charged.
test('a counsellor listed pricing rate has no effect on the amount a client is charged for a therapy', function () {
    $counsellor = aCounsellorForPricing();
    setPricing($counsellor, [['amount' => 999999, 'currency' => 'GHS']]);

    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => $counsellor->id,
        'session_type' => TherapySessionTypeEnum::once->value,
        'status' => TherapyStatusEnum::in_session->value,
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => TherapyPerPaymentEnum::therapy->value, 'amount' => 150, 'currency' => 'GHS'],
    ]);

    $payable = GetPayableAmountAction::new()->execute($therapy);

    expect($payable['amount'])->toBe(150);
});
