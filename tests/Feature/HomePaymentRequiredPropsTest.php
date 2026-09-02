<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

// SCRUM-221/TT-7.5a: confirms the payment-required flash keys a blocked client's redirect sets
// (TherapyController::redirectForPaymentRequired) actually surface as Home page props, and that
// the payment-required case does NOT also trigger the generic red "failed" toast (errorMessage).

test('Home surfaces the payment-required props from the flash session', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withSession([
            'message' => 'Payment is required to access therapy with id: 1',
            'paymentRequired' => true,
            'paymentRequiredTherapyId' => 1,
        ])
        ->get(route('home'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Home')
        ->where('paymentRequired', true)
        ->where('paymentRequiredTherapyId', 1)
        ->where('paymentRequiredMessage', 'Payment is required to access therapy with id: 1')
    );
});

test('a payment-required redirect does not also trigger the generic errorMessage toast', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withSession([
            'message' => 'Payment is required to access therapy with id: 1',
            'paymentRequired' => true,
            'paymentRequiredTherapyId' => 1,
        ])
        ->get(route('home'));

    $response->assertInertia(fn (Assert $page) => $page->missing('errorMessage'));
});

test('a normal (non-payment-required) flashed message still triggers the generic errorMessage toast', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withSession(['message' => 'You are not allowed to access this.'])
        ->get(route('home'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('errorMessage', 'You are not allowed to access this.')
    );
});
