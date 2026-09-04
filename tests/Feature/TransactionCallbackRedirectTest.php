<?php

use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;

// TT-7.3b-a/SCRUM-231 (security-engineer finding): TransactionController::redirectUrlFor() is a
// shared choke point every checkout flow's callback_url points back to -- an org-payment-
// instrument-registration transaction (subject is an Organization, not a Therapy/Session/
// GroupTherapy) must not be mishandled here even though no route creates one yet (TT-7.3b-i's
// controller will).

test('the transaction callback redirects an organization-subject transaction to the org dashboard, not a therapy page', function () {
    Http::fake(['*/transaction/verify/*' => Http::response([
        'status' => true,
        'data' => ['status' => 'success', 'amount' => 100, 'currency' => 'GHS', 'gateway_response' => 'Successful'],
    ], 200)]);

    $admin = User::factory()->create();
    $organization = Organization::factory()->create();
    $transaction = Transaction::factory()->create([
        'for_type' => Organization::class,
        'for_id' => $organization->id,
        'user_id' => $admin->id,
        'reference' => 'org_callback_ref_1',
        'amount' => 100,
        'currency' => 'GHS',
    ]);

    $response = $this->actingAs($admin)->get(route('transactions.callback', ['reference' => 'org_callback_ref_1']));

    $response->assertRedirect(route('organizations.dashboard', ['organizationId' => $organization->id]));
});
