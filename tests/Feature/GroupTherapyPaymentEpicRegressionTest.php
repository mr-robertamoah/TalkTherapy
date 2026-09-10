<?php

use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Enums\TransactionStatusEnum;
use App\Models\GroupTherapy;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;

// TT-7.4d-e/SCRUM-262: epic closeout regression coverage for the whole group-therapy per-member
// payment epic (TT-7.4d-a through -d). The four bullets this ticket asks for are already covered
// individually and thoroughly across the epic's own sub-ticket test files:
//   - privacy (a member never sees another's payment status): PaymentStatusExposureTest.php
//   - refund independence: TransactionControllerRequestRefundTest.php
//   - the counsellor roster's real-identity/anonymity exception: GroupTherapyPaymentRosterTest.php
//   - authorization (a member can pay after another already has): EnsureCanInitiateChargeActionGroupTherapyTest.php
// This file adds the one genuine gap: none of those exercise the actual HTTP route/controller
// layer for initiating a charge (only the TransactionService/Action layer directly) -- and
// TT-7.4d-b's own routing fix (payForTherapy() posting to the wrong route entirely) was only ever
// caught by Playwright QA, not a Pest test. This closes that gap.

test('two different members of the same group therapy can each initiate a charge via the real HTTP route', function () {
    $counsellorUser = User::factory()->create();
    $counsellor = $counsellorUser->counsellor()->create(['email' => fake()->unique()->safeEmail(), 'about' => 'test']);
    $groupTherapy = GroupTherapy::factory()->create([
        'payment_type' => 'PAID',
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 100, 'currency' => 'GHS'],
        'public' => true,
    ]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);
    $memberA = User::factory()->create();
    $memberB = User::factory()->create();
    $groupTherapy->users()->attach($memberA->id, ['anonymous' => false]);
    $groupTherapy->users()->attach($memberB->id, ['anonymous' => false]);

    // A sequence, not two separate Http::fake() calls -- the second call to Http::fake() does not
    // override a still-matching URL pattern from the first, so both requests would otherwise
    // receive the SAME first-registered response (and therefore the same Paystack `reference`,
    // which collides against transactions.reference's own unique constraint on the second insert).
    Http::fake([
        '*/transaction/initialize' => Http::sequence()
            ->push(['status' => true, 'message' => 'ok', 'data' => [
                'authorization_url' => 'https://checkout.paystack.com/ref_member_a',
                'access_code' => 'ref_member_a',
                'reference' => 'ref_member_a',
            ]], 200)
            ->push(['status' => true, 'message' => 'ok', 'data' => [
                'authorization_url' => 'https://checkout.paystack.com/ref_member_b',
                'access_code' => 'ref_member_b',
                'reference' => 'ref_member_b',
            ]], 200),
    ]);

    // memberA pays first.
    $this->actingAs($memberA)
        ->postJson(route('transactions.initiate.group_therapy', ['groupTherapyId' => $groupTherapy->id]))
        ->assertOk()
        ->assertJsonPath('authorizationUrl', 'https://checkout.paystack.com/ref_member_a');

    Transaction::where('user_id', $memberA->id)->update(['status' => TransactionStatusEnum::success->value]);

    // memberB must still be able to pay, via the SAME route, even though memberA already
    // succeeded -- this is exactly the route TT-7.4d-b's payForTherapy() fix ensures the frontend
    // actually posts to, and exactly the guard SCRUM-257 fixed to be per-user, not per-group.
    $this->actingAs($memberB)
        ->postJson(route('transactions.initiate.group_therapy', ['groupTherapyId' => $groupTherapy->id]))
        ->assertOk()
        ->assertJsonPath('authorizationUrl', 'https://checkout.paystack.com/ref_member_b');

    expect(Transaction::where('for_type', GroupTherapy::class)->where('for_id', $groupTherapy->id)->count())->toBe(2)
        ->and(Transaction::where('user_id', $memberB->id)->exists())->toBeTrue();
});

// The individual-Therapy route must never accept a GroupTherapy id -- TransactionController::getFor()
// resolves strictly by which route param is present, so this would either 404 or, worse, silently
// resolve an unrelated Therapy record that happens to share the same id. This is the exact defect
// TT-7.4d-b's own payForTherapy() fix exists to prevent the frontend from ever triggering.
test('a GroupTherapy id posted to the individual-Therapy route never resolves the group at all', function () {
    $groupTherapy = GroupTherapy::factory()->create([
        'payment_type' => 'PAID',
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 100, 'currency' => 'GHS'],
    ]);
    $member = User::factory()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false]);

    $response = $this->actingAs($member)
        ->postJson(route('transactions.initiate.therapy', ['therapyId' => $groupTherapy->id]));

    // getFor() resolves via Therapy::find() for this route regardless of what id is passed --
    // since no Therapy with this id exists (it's a GroupTherapy id), `for` is null and the charge
    // is rejected, rather than ever being silently attributed to the wrong payable.
    $response->assertStatus(422);
    expect(Transaction::count())->toBe(0);
});
