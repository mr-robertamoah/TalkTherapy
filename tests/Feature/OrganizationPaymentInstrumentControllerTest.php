<?php

use App\Enums\OrganizationAdminRoleEnum;
use App\Models\Organization;
use App\Models\OrganizationPaymentInstrument;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;

// TT-7.3b-i/SCRUM-240: org-admin "add/replace payment method" screen -- the TT-7.3b-a backend
// had no controller/route/UI of its own until this ticket. Mirrors
// OrganizationReconciliationControllerTest's own admin-gating/cross-org-isolation coverage shape.

function anOrgAdminForPaymentInstrument(array $overrides = []): array
{
    $organization = Organization::factory()->create(array_merge(['is_consumer' => true, 'verified_at' => now()], $overrides));
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);

    return [$organization, $owner];
}

test('an org admin can load the payment-instrument page with no instrument on file yet', function () {
    [$organization, $owner] = anOrgAdminForPaymentInstrument();

    $this->actingAs($owner);

    $response = $this->get(route('organizations.payment_instrument', ['organizationId' => $organization->id]));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->component('Organization/PaymentInstrument')
        ->where('organization.id', $organization->id)
        ->where('paymentInstrument', null)
        ->has('verificationAmounts')
    );
});

test('an org admin sees the existing instrument\'s masked details, never raw card data', function () {
    [$organization, $owner] = anOrgAdminForPaymentInstrument();
    OrganizationPaymentInstrument::factory()->create([
        'organization_id' => $organization->id,
        'masked_card_number' => '**** 4242',
        'card_type' => 'visa',
        'bank' => 'Test Bank',
        'exp_month' => '12',
        'exp_year' => '2030',
        'currency' => 'GHS',
        'pending_credit_amount' => 100,
    ]);

    $this->actingAs($owner);

    $response = $this->get(route('organizations.payment_instrument', ['organizationId' => $organization->id]));

    $response->assertInertia(function ($page) {
        $instrument = $page->toArray()['props']['paymentInstrument'];

        expect($instrument['maskedCardNumber'])->toBe('**** 4242');
        expect($instrument['cardType'])->toBe('visa');
        expect($instrument['bank'])->toBe('Test Bank');
        expect($instrument['expMonth'])->toBe('12');
        expect($instrument['expYear'])->toBe('2030');
        expect($instrument['currency'])->toBe('GHS');
        expect($instrument['pendingCreditAmount'])->toBe(100);
    });
});

test('a non-admin is redirected home rather than seeing a raw error page', function () {
    [$organization] = anOrgAdminForPaymentInstrument();
    $outsider = User::factory()->create();

    $this->actingAs($outsider);

    $response = $this->get(route('organizations.payment_instrument', ['organizationId' => $organization->id]));

    $response->assertRedirect(route('home'));
});

test('an admin of a different organization is redirected home, not shown this one\'s payment-instrument page', function () {
    [$organizationA] = anOrgAdminForPaymentInstrument();
    [, $adminOfB] = anOrgAdminForPaymentInstrument();

    $this->actingAs($adminOfB);

    $response = $this->get(route('organizations.payment_instrument', ['organizationId' => $organizationA->id]));

    $response->assertRedirect(route('home'));
});

test('a nonexistent organization redirects home rather than a raw error page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('organizations.payment_instrument', ['organizationId' => 999999]));

    $response->assertRedirect(route('home'));
});

test('a guest is redirected to login', function () {
    [$organization] = anOrgAdminForPaymentInstrument();

    $response = $this->get(route('organizations.payment_instrument', ['organizationId' => $organization->id]));

    $response->assertRedirect(route('login'));
});

test('an org admin can initiate payment-instrument registration via the real HTTP endpoint', function () {
    Http::fake(['*/transaction/initialize' => Http::response([
        'status' => true,
        'data' => ['reference' => 'org_reg_http_1', 'authorization_url' => 'https://checkout.paystack.com/org_reg_http_1'],
    ], 200)]);
    [$organization, $owner] = anOrgAdminForPaymentInstrument();

    $this->actingAs($owner);

    $response = $this->postJson(route('organizations.payment_instrument.initiate', ['organizationId' => $organization->id]), [
        'currency' => 'GHS',
    ]);

    $response->assertOk();
    $response->assertJson(['authorizationUrl' => 'https://checkout.paystack.com/org_reg_http_1']);
    $this->assertDatabaseHas('transactions', [
        'for_type' => Organization::class,
        'for_id' => $organization->id,
        'user_id' => $owner->id,
        'reference' => 'org_reg_http_1',
    ]);
});

test('an unsupported currency is rejected with a validation error, never reaching Paystack', function () {
    [$organization, $owner] = anOrgAdminForPaymentInstrument();

    $this->actingAs($owner);

    $response = $this->postJson(route('organizations.payment_instrument.initiate', ['organizationId' => $organization->id]), [
        'currency' => 'XYZ',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('currency');
    $this->assertDatabaseCount('transactions', 0);
});

test('a non-admin cannot initiate payment-instrument registration for someone else\'s organization', function () {
    [$organization] = anOrgAdminForPaymentInstrument();
    $outsider = User::factory()->create();

    $this->actingAs($outsider);

    $response = $this->postJson(route('organizations.payment_instrument.initiate', ['organizationId' => $organization->id]), [
        'currency' => 'GHS',
    ]);

    $response->assertForbidden();
    $this->assertDatabaseCount('transactions', 0);
});

// The org must be verified + consumer-capable (EnsureCanRegisterOrganizationPaymentInstrumentAction's
// own gate, already unit-tested) -- this pins that the real HTTP route actually reaches it.
test('an unverified organization cannot initiate payment-instrument registration via the real route', function () {
    [$organization, $owner] = anOrgAdminForPaymentInstrument(['verified_at' => null]);

    $this->actingAs($owner);

    $response = $this->postJson(route('organizations.payment_instrument.initiate', ['organizationId' => $organization->id]), [
        'currency' => 'GHS',
    ]);

    $response->assertStatus(422);
    $this->assertDatabaseCount('transactions', 0);
});

test('replacing an existing payment instrument redirects the post-checkout callback back to this page, not the dashboard', function () {
    $organization = Organization::factory()->create(['is_consumer' => true, 'verified_at' => now()]);
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
    Http::fake(['*/transaction/verify/*' => Http::response([
        'status' => true,
        'data' => ['status' => 'success', 'reference' => 'org_reg_cb_1', 'amount' => 100, 'currency' => 'GHS', 'gateway_response' => 'Approved'],
    ], 200)]);
    Transaction::factory()->create([
        'for_type' => Organization::class,
        'for_id' => $organization->id,
        'user_id' => $owner->id,
        'reference' => 'org_reg_cb_1',
        'status' => 'PENDING',
        'amount' => 100,
        'currency' => 'GHS',
    ]);

    $this->actingAs($owner);

    $response = $this->get(route('transactions.callback', ['reference' => 'org_reg_cb_1']));

    $response->assertRedirect(route('organizations.payment_instrument', ['organizationId' => $organization->id]));
});
