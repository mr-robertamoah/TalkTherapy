<?php

use App\Actions\Organization\InitiateOrganizationPaymentInstrumentRegistrationAction;
use App\DTOs\OrganizationPaymentInstrumentDTO;
use App\Enums\OrganizationAdminRoleEnum;
use App\Enums\TransactionStatusEnum;
use App\Exceptions\OrganizationException;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;

// TT-7.3b-a/SCRUM-231: Paystack has no "just verify this card" call -- registering an org's
// payment instrument means running one small, nominal charge through it, mirroring
// InitiatePaystackChargeAction's own shape.

function aRegistrationOrgAdmin(): array
{
    $admin = User::factory()->create();
    $organization = Organization::factory()->create(['is_consumer' => true, 'verified_at' => now()]);
    $organization->admins()->attach($admin->id, ['role' => OrganizationAdminRoleEnum::owner->value]);

    return [$organization, $admin];
}

test('initiating registration creates a pending transaction against the organization and returns an authorization URL', function () {
    Http::fake(['*/transaction/initialize' => Http::response([
        'status' => true,
        'data' => ['reference' => 'org_reg_ref_1', 'authorization_url' => 'https://checkout.paystack.com/org_reg_ref_1'],
    ], 200)]);
    [$organization, $admin] = aRegistrationOrgAdmin();

    $result = InitiateOrganizationPaymentInstrumentRegistrationAction::new()->execute(OrganizationPaymentInstrumentDTO::new()->fromArray([
        'user' => $admin,
        'organization' => $organization,
        'currency' => 'GHS',
        'callbackUrl' => 'https://app.test/callback',
    ]));

    expect($result['authorizationUrl'])->toBe('https://checkout.paystack.com/org_reg_ref_1');
    expect($result['transaction'])->toBeInstanceOf(Transaction::class);
    expect($result['transaction']->for_type)->toBe(Organization::class);
    expect($result['transaction']->for_id)->toBe($organization->id);
    expect($result['transaction']->user_id)->toBe($admin->id);
    expect($result['transaction']->status)->toBe(TransactionStatusEnum::pending->value);
    expect($result['transaction']->amount)->toBe(config('settings.organization_payment_instrument_verification_amount.GHS'));
    $this->assertDatabaseCount('transactions', 1);
});

test('an unauthorized caller cannot initiate registration', function () {
    $organization = Organization::factory()->create(['is_consumer' => true, 'verified_at' => now()]);
    $plainUser = User::factory()->create();

    InitiateOrganizationPaymentInstrumentRegistrationAction::new()->execute(OrganizationPaymentInstrumentDTO::new()->fromArray([
        'user' => $plainUser,
        'organization' => $organization,
        'currency' => 'GHS',
    ]));
})->throws(OrganizationException::class);

test('a failed Paystack initialize call is surfaced as a clean OrganizationException, not an uncaught 500', function () {
    Http::fake(['*/transaction/initialize' => Http::response(['status' => false, 'message' => 'failed'], 502)]);
    [$organization, $admin] = aRegistrationOrgAdmin();

    InitiateOrganizationPaymentInstrumentRegistrationAction::new()->execute(OrganizationPaymentInstrumentDTO::new()->fromArray([
        'user' => $admin,
        'organization' => $organization,
        'currency' => 'GHS',
    ]));
})->throws(OrganizationException::class);

test('no Transaction row is created when the Paystack call fails', function () {
    Http::fake(['*/transaction/initialize' => Http::response(['status' => false], 502)]);
    [$organization, $admin] = aRegistrationOrgAdmin();

    try {
        InitiateOrganizationPaymentInstrumentRegistrationAction::new()->execute(OrganizationPaymentInstrumentDTO::new()->fromArray([
            'user' => $admin,
            'organization' => $organization,
            'currency' => 'GHS',
        ]));
    } catch (OrganizationException) {
        //
    }

    $this->assertDatabaseCount('transactions', 0);
});
