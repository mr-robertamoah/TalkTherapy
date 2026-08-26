<?php

use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapyPerPaymentEnum;
use App\Enums\TherapySessionTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('an authenticated user can initiate a charge for a paid therapy via the real route', function () {
    Http::fake([
        '*/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/ref_route_1',
                'reference' => 'ref_route_1',
            ],
        ], 200),
    ]);

    $therapyOwner = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $therapyOwner->id,
        'session_type' => TherapySessionTypeEnum::once->value,
        'status' => TherapyStatusEnum::in_session->value,
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => TherapyPerPaymentEnum::therapy->value, 'amount' => 150, 'currency' => 'GHS'],
    ]);

    $this->actingAs($therapyOwner);

    $response = $this->postJson("/therapies/{$therapy->id}/transactions");

    $response->assertOk();
    $response->assertJson(['authorizationUrl' => 'https://checkout.paystack.com/ref_route_1']);

    $this->assertDatabaseHas('transactions', [
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'user_id' => $therapyOwner->id,
        'reference' => 'ref_route_1',
    ]);
});

test('initiating a charge for a therapy that does not exist returns a clean error, not a crash', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->postJson('/therapies/999999/transactions');

    $response->assertStatus(422);
    $response->assertJson(['message' => 'The item you are trying to pay for was not found.']);
});

// SCRUM-110 security review: originally, any signed-in user could POST to this route for an
// arbitrary therapy id -- belonging to someone else, public or private -- and get back a real
// Paystack checkout URL plus that record's price.

test('a user with no relationship to the therapy is rejected via the real route, not given a checkout link', function () {
    $therapyOwner = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $therapyOwner->id,
        'session_type' => TherapySessionTypeEnum::once->value,
        'status' => TherapyStatusEnum::in_session->value,
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => TherapyPerPaymentEnum::therapy->value, 'amount' => 150, 'currency' => 'GHS'],
    ]);

    $outsider = User::factory()->create();
    $this->actingAs($outsider);

    $response = $this->postJson("/therapies/{$therapy->id}/transactions");

    $response->assertStatus(403);
    $response->assertJson(['message' => 'You are not authorized to pay for this.']);
    $this->assertDatabaseMissing('transactions', ['for_type' => Therapy::class, 'for_id' => $therapy->id]);
});

// SCRUM-110 security review: Request::__get() prefers a same-named key from the parsed request
// body/query over the route parameter, so TransactionController::getFor() originally resolved
// the charge target from client-controlled input, making the URL path purely decorative.

test('the resolved payment target comes from the URL route parameter, not a spoofable request body field', function () {
    Http::fake([
        '*/transaction/initialize' => Http::response([
            'status' => true,
            'data' => ['authorization_url' => 'https://checkout.paystack.com/ref_url_wins', 'reference' => 'ref_url_wins'],
        ], 200),
    ]);

    $owner = User::factory()->create();
    $ownedTherapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $owner->id,
        'session_type' => TherapySessionTypeEnum::once->value,
        'status' => TherapyStatusEnum::in_session->value,
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => TherapyPerPaymentEnum::therapy->value, 'amount' => 150, 'currency' => 'GHS'],
    ]);

    $unrelatedTherapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => TherapyPerPaymentEnum::therapy->value, 'amount' => 999, 'currency' => 'GHS'],
    ]);

    $this->actingAs($owner);

    // If getFor() ever regresses to reading the magic ->therapyId property, this spoofed body
    // field would win over the URL's {therapyId}, and since $owner isn't authorized for
    // $unrelatedTherapy, the request would fail with 403 instead of succeeding for the therapy
    // the URL actually names.
    $response = $this->postJson("/therapies/{$ownedTherapy->id}/transactions", [
        'therapyId' => $unrelatedTherapy->id,
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('transactions', ['for_type' => Therapy::class, 'for_id' => $ownedTherapy->id]);
    $this->assertDatabaseMissing('transactions', ['for_type' => Therapy::class, 'for_id' => $unrelatedTherapy->id]);
});
