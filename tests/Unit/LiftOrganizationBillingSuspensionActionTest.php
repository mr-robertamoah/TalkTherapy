<?php

use App\Actions\Organization\LiftOrganizationBillingSuspensionAction;
use App\Exceptions\OrganizationException;
use App\Models\Administrator;
use App\Models\Organization;
use App\Models\User;

// TT-7.3b-followup/SCRUM-245: the manual "resolve this" path SCRUM-238 deliberately left unbuilt.

test('a platform admin can lift a suspended organization\'s billing suspension', function () {
    $admin = User::factory()->has(Administrator::factory())->create();
    $organization = Organization::factory()->create();
    $organization->suspendBilling('Retainer invoice settlement failed.');

    $result = LiftOrganizationBillingSuspensionAction::new()->execute($admin, $organization);

    expect($result->isBillingSuspended())->toBeFalse();
    expect($result->billing_suspension_reason)->toBeNull();
});

test('lifting an already-unsuspended organization is a harmless no-op', function () {
    $admin = User::factory()->has(Administrator::factory())->create();
    $organization = Organization::factory()->create();

    $result = LiftOrganizationBillingSuspensionAction::new()->execute($admin, $organization);

    expect($result->isBillingSuspended())->toBeFalse();
});

test('a non-admin cannot lift a billing suspension', function () {
    $plainUser = User::factory()->create();
    $organization = Organization::factory()->create();
    $organization->suspendBilling('Retainer invoice settlement failed.');

    LiftOrganizationBillingSuspensionAction::new()->execute($plainUser, $organization);
})->throws(OrganizationException::class);

test('a null user cannot lift a billing suspension', function () {
    $organization = Organization::factory()->create();
    $organization->suspendBilling('Retainer invoice settlement failed.');

    LiftOrganizationBillingSuspensionAction::new()->execute(null, $organization);
})->throws(OrganizationException::class);

test('a nonexistent organization throws a clean error, not a crash', function () {
    $admin = User::factory()->has(Administrator::factory())->create();

    LiftOrganizationBillingSuspensionAction::new()->execute($admin, null);
})->throws(OrganizationException::class, 'Organization not found.');
