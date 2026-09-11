<?php

use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\User;

// TT-7.5b-b2/SCRUM-266: mirrors PaymentRequiredRedirectTest.php's own Therapy coverage --
// verifies the HTTP-level redirect for a GroupTherapy member blocked by the strict payment gate,
// at both entry points that share EnsureUserHasAccessToTherapyAction.

function strictGatedPaidGroupTherapyForHttp(User $member): GroupTherapy
{
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'public' => false,
        'payment_type' => 'PAID',
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60, 'strictPaymentGate' => true],
    ]);
    $groupTherapy->users()->attach($member->id, ['anonymous' => false]);

    return $groupTherapy;
}

test('a blocked member visiting the group therapy page is redirected home with the group-payment-required flash keys', function () {
    $member = User::factory()->create();
    $groupTherapy = strictGatedPaidGroupTherapyForHttp($member);

    $response = $this->actingAs($member)->get("/group-therapies/{$groupTherapy->id}");

    $response->assertRedirect(route('home'));
    $response->assertSessionHas('paymentRequired', true);
    $response->assertSessionHas('paymentRequiredGroupTherapyId', (string) $groupTherapy->id);
    $response->assertSessionHas('message');
});

test('a blocked member visiting the group therapy chat page gets the same payment-required redirect', function () {
    $member = User::factory()->create();
    $groupTherapy = strictGatedPaidGroupTherapyForHttp($member);

    $response = $this->actingAs($member)->get("/group-therapies/{$groupTherapy->id}/chat");

    $response->assertRedirect(route('home'));
    $response->assertSessionHas('paymentRequired', true);
    $response->assertSessionHas('paymentRequiredGroupTherapyId', (string) $groupTherapy->id);
});

test('an active counsellor visiting the group therapy page is never redirected for payment', function () {
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'public' => false,
        'payment_type' => 'PAID',
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60, 'strictPaymentGate' => true],
    ]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);

    $response = $this->actingAs($counsellorUser)->get("/group-therapies/{$groupTherapy->id}");

    $response->assertOk();
    $response->assertSessionMissing('paymentRequired');
});
