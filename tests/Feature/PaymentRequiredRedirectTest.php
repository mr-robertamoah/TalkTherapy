<?php

use App\Models\Therapy;
use App\Models\User;

// SCRUM-219/TT-7.5a: verifies the HTTP-level redirect behavior for a client blocked by the
// strict payment gate, at both entry points that share EnsureUserHasAccessToTherapyAction.

function strictGatedPaidTherapyForHttp(User $client): Therapy
{
    return Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'public' => false,
        'payment_type' => 'PAID',
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60, 'strictPaymentGate' => true],
    ]);
}

test('a blocked client visiting the therapy page is redirected home with the payment-required flash keys', function () {
    $client = User::factory()->create();
    $therapy = strictGatedPaidTherapyForHttp($client);

    $response = $this->actingAs($client)->get("/therapies/{$therapy->id}");

    $response->assertRedirect(route('home'));
    $response->assertSessionHas('paymentRequired', true);
    $response->assertSessionHas('paymentRequiredTherapyId', (string) $therapy->id);
    $response->assertSessionHas('message');
});

test('a blocked client visiting the therapy chat page gets the same payment-required redirect', function () {
    $client = User::factory()->create();
    $therapy = strictGatedPaidTherapyForHttp($client);

    $response = $this->actingAs($client)->get("/therapies/{$therapy->id}/chat");

    $response->assertRedirect(route('home'));
    $response->assertSessionHas('paymentRequired', true);
    $response->assertSessionHas('paymentRequiredTherapyId', (string) $therapy->id);
});
