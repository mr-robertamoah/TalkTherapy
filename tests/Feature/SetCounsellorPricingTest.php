<?php

use App\Enums\SessionTypeEnum;
use App\Enums\TherapyPerPaymentEnum;
use App\Enums\TherapyTypeEnum;
use App\Models\Counsellor;
use App\Models\CounsellorPricing;
use App\Models\User;

// SCRUM-154 (TT-7.2b): reviewer-requested Feature coverage -- the Unit suite
// (tests/Unit/CounsellorPricingServiceTest.php) calls CounsellorPricingService directly and never
// exercises SetCounsellorPricingRequest's actual validation rules or the controller's real HTTP
// response mapping. These tests close that gap.

function aCounsellorWithUserForPricingRoute(): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()]);
}

test('an authenticated counsellor can set their own flat pricing via the real route', function () {
    $counsellor = aCounsellorWithUserForPricingRoute();
    $this->actingAs($counsellor->user);

    $response = $this->postJson("/counsellor/{$counsellor->id}/pricings", [
        'pricings' => [['amount' => 150, 'currency' => 'GHS']],
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('counsellor_pricings', [
        'counsellor_id' => $counsellor->id,
        'amount' => 150,
        'currency' => 'GHS',
        'therapy_type' => null,
    ]);
});

test('an unsupported currency is rejected with a 422 through the real validation pipeline', function () {
    $counsellor = aCounsellorWithUserForPricingRoute();
    $this->actingAs($counsellor->user);

    $response = $this->postJson("/counsellor/{$counsellor->id}/pricings", [
        'pricings' => [['amount' => 150, 'currency' => 'XYZ']],
    ]);

    $response->assertStatus(422);
    $this->assertDatabaseMissing('counsellor_pricings', ['counsellor_id' => $counsellor->id]);
});

test('a non-integer amount is rejected with a 422 through the real validation pipeline', function () {
    $counsellor = aCounsellorWithUserForPricingRoute();
    $this->actingAs($counsellor->user);

    $response = $this->postJson("/counsellor/{$counsellor->id}/pricings", [
        'pricings' => [['amount' => 'not-a-number', 'currency' => 'GHS']],
    ]);

    $response->assertStatus(422);
});

test('a counsellor cannot set pricing for another counsellor via the real route', function () {
    $counsellor = aCounsellorWithUserForPricingRoute();
    $otherCounsellor = aCounsellorWithUserForPricingRoute();
    $this->actingAs($otherCounsellor->user);

    $response = $this->postJson("/counsellor/{$counsellor->id}/pricings", [
        'pricings' => [['amount' => 150, 'currency' => 'GHS']],
    ]);

    $response->assertStatus(403);
});

test('setting new pricing via the real route atomically replaces an existing configuration', function () {
    $counsellor = aCounsellorWithUserForPricingRoute();
    $staleFlatRate = CounsellorPricing::factory()->create([
        'counsellor_id' => $counsellor->id,
        'amount' => 999,
        'currency' => 'GHS',
    ]);
    $this->actingAs($counsellor->user);

    $response = $this->postJson("/counsellor/{$counsellor->id}/pricings", [
        'pricings' => [
            ['therapyType' => TherapyTypeEnum::individual->value, 'sessionType' => SessionTypeEnum::online->value, 'per' => TherapyPerPaymentEnum::session->value, 'amount' => 100, 'currency' => 'GHS'],
        ],
    ]);

    $response->assertOk();
    $this->assertModelMissing($staleFlatRate);
    $this->assertDatabaseHas('counsellor_pricings', [
        'counsellor_id' => $counsellor->id,
        'therapy_type' => TherapyTypeEnum::individual->value,
        'amount' => 100,
    ]);
    expect(CounsellorPricing::query()->where('counsellor_id', $counsellor->id)->count())->toBe(1);
});

// SCRUM-155 (TT-7.2c): the Unit suite (tests/Unit/CounsellorPricingServiceTest.php) calls
// CounsellorPricingService::clearPricing() directly, bypassing the controller/route/FormRequest
// layer entirely -- these close that gap for the new destroy() endpoint, mirroring why the tests
// above exist for store() (reviewer finding).

test('a counsellor can clear their pricing via the real route', function () {
    $counsellor = aCounsellorWithUserForPricingRoute();
    CounsellorPricing::factory()->create(['counsellor_id' => $counsellor->id, 'amount' => 150, 'currency' => 'GHS']);
    $this->actingAs($counsellor->user);

    $response = $this->deleteJson("/counsellor/{$counsellor->id}/pricings");

    $response->assertOk();
    $response->assertJson(['pricings' => []]);
    $this->assertDatabaseMissing('counsellor_pricings', ['counsellor_id' => $counsellor->id]);
});

test('a counsellor cannot clear another counsellor\'s pricing via the real route', function () {
    $counsellor = aCounsellorWithUserForPricingRoute();
    $otherCounsellor = aCounsellorWithUserForPricingRoute();
    CounsellorPricing::factory()->create(['counsellor_id' => $counsellor->id, 'amount' => 150, 'currency' => 'GHS']);
    $this->actingAs($otherCounsellor->user);

    $response = $this->deleteJson("/counsellor/{$counsellor->id}/pricings");

    $response->assertStatus(403);
    $this->assertDatabaseHas('counsellor_pricings', ['counsellor_id' => $counsellor->id]);
});
