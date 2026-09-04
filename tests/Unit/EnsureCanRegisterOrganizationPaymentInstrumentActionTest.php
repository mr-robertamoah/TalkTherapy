<?php

use App\Actions\Organization\EnsureCanRegisterOrganizationPaymentInstrumentAction;
use App\DTOs\OrganizationPaymentInstrumentDTO;
use App\Enums\OrganizationAdminRoleEnum;
use App\Exceptions\OrganizationException;
use App\Models\Organization;
use App\Models\User;

// TT-7.3b-a/SCRUM-231: mirrors EnsureCanOnboardPayoutDestinationAction's own eligibility-check
// test coverage shape.

function aVerifiedConsumerOrgWithAdmin(): array
{
    $admin = User::factory()->create();
    $organization = Organization::factory()->create(['is_consumer' => true, 'verified_at' => now()]);
    $organization->admins()->attach($admin->id, ['role' => OrganizationAdminRoleEnum::owner->value]);

    return [$organization, $admin];
}

test('an org admin can register a payment instrument for a verified, consumer-capable org', function () {
    [$organization, $admin] = aVerifiedConsumerOrgWithAdmin();

    expect(fn () => EnsureCanRegisterOrganizationPaymentInstrumentAction::new()->execute(OrganizationPaymentInstrumentDTO::new()->fromArray([
        'user' => $admin,
        'organization' => $organization,
    ])))->not->toThrow(OrganizationException::class);
});

test('a non-admin cannot register a payment instrument', function () {
    $organization = Organization::factory()->create(['is_consumer' => true, 'verified_at' => now()]);
    $plainUser = User::factory()->create();

    EnsureCanRegisterOrganizationPaymentInstrumentAction::new()->execute(OrganizationPaymentInstrumentDTO::new()->fromArray([
        'user' => $plainUser,
        'organization' => $organization,
    ]));
})->throws(OrganizationException::class);

test('an unverified organization cannot register a payment instrument', function () {
    $admin = User::factory()->create();
    $organization = Organization::factory()->create(['is_consumer' => true, 'verified_at' => null]);
    $organization->admins()->attach($admin->id, ['role' => OrganizationAdminRoleEnum::owner->value]);

    EnsureCanRegisterOrganizationPaymentInstrumentAction::new()->execute(OrganizationPaymentInstrumentDTO::new()->fromArray([
        'user' => $admin,
        'organization' => $organization,
    ]));
})->throws(OrganizationException::class);

test('a non-consumer organization cannot register a payment instrument', function () {
    $admin = User::factory()->create();
    $organization = Organization::factory()->create(['is_consumer' => false, 'verified_at' => now()]);
    $organization->admins()->attach($admin->id, ['role' => OrganizationAdminRoleEnum::owner->value]);

    EnsureCanRegisterOrganizationPaymentInstrumentAction::new()->execute(OrganizationPaymentInstrumentDTO::new()->fromArray([
        'user' => $admin,
        'organization' => $organization,
    ]));
})->throws(OrganizationException::class);
