<?php

use App\DTOs\OrganizationMemberBillingConfigDTO;
use App\Enums\OrganizationAdminRoleEnum;
use App\Enums\OrganizationMemberBillingModeEnum;
use App\Enums\TherapyPerPaymentEnum;
use App\Exceptions\OrganizationException;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Services\OrganizationMemberBillingConfigService;

function activeMembership(): array
{
    $organization = Organization::factory()->create(['is_provider' => false, 'is_consumer' => true, 'verified_at' => now()]);
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
    $member = OrganizationMember::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => User::factory(),
    ]);

    return [$member, $organization, $owner];
}

test('an org admin can set a retainer billing configuration', function () {
    [$member, , $owner] = activeMembership();

    $config = OrganizationMemberBillingConfigService::new()->setBillingConfig(
        OrganizationMemberBillingConfigDTO::new()->fromArray([
            'user' => $owner,
            'organizationMember' => $member,
            'mode' => OrganizationMemberBillingModeEnum::retainer->value,
            'includeGroupTherapies' => true,
        ])
    );

    expect($config->mode)->toBe(OrganizationMemberBillingModeEnum::retainer->value);
    expect($config->per)->toBeNull();
    expect($config->include_group_therapies)->toBeTrue();
});

test('an org admin can set a pay-per-use billing configuration with a per granularity', function () {
    [$member, , $owner] = activeMembership();

    $config = OrganizationMemberBillingConfigService::new()->setBillingConfig(
        OrganizationMemberBillingConfigDTO::new()->fromArray([
            'user' => $owner,
            'organizationMember' => $member,
            'mode' => OrganizationMemberBillingModeEnum::payPerUse->value,
            'per' => TherapyPerPaymentEnum::session->value,
            'includeGroupTherapies' => false,
        ])
    );

    expect($config->mode)->toBe(OrganizationMemberBillingModeEnum::payPerUse->value);
    expect($config->per)->toBe(TherapyPerPaymentEnum::session->value);
    expect($config->include_group_therapies)->toBeFalse();
});

test('a retainer configuration carrying a leftover per granularity is rejected', function () {
    [$member, , $owner] = activeMembership();

    expect(fn () => OrganizationMemberBillingConfigService::new()->setBillingConfig(
        OrganizationMemberBillingConfigDTO::new()->fromArray([
            'user' => $owner,
            'organizationMember' => $member,
            'mode' => OrganizationMemberBillingModeEnum::retainer->value,
            'per' => TherapyPerPaymentEnum::therapy->value,
            'includeGroupTherapies' => true,
        ])
    ))->toThrow(OrganizationException::class);
});

test('a pay-per-use configuration without a per granularity is rejected', function () {
    [$member, , $owner] = activeMembership();

    expect(fn () => OrganizationMemberBillingConfigService::new()->setBillingConfig(
        OrganizationMemberBillingConfigDTO::new()->fromArray([
            'user' => $owner,
            'organizationMember' => $member,
            'mode' => OrganizationMemberBillingModeEnum::payPerUse->value,
            'includeGroupTherapies' => true,
        ])
    ))->toThrow(OrganizationException::class);
});

test('a configuration without an explicit group-therapy inclusion flag is rejected', function () {
    [$member, , $owner] = activeMembership();

    expect(fn () => OrganizationMemberBillingConfigService::new()->setBillingConfig(
        OrganizationMemberBillingConfigDTO::new()->fromArray([
            'user' => $owner,
            'organizationMember' => $member,
            'mode' => OrganizationMemberBillingModeEnum::retainer->value,
        ])
    ))->toThrow(OrganizationException::class);
});

test('a user who does not administer the organization cannot set billing configuration', function () {
    [$member] = activeMembership();
    $outsider = User::factory()->create();

    expect(fn () => OrganizationMemberBillingConfigService::new()->setBillingConfig(
        OrganizationMemberBillingConfigDTO::new()->fromArray([
            'user' => $outsider,
            'organizationMember' => $member,
            'mode' => OrganizationMemberBillingModeEnum::retainer->value,
            'includeGroupTherapies' => true,
        ])
    ))->toThrow(OrganizationException::class);
});

test('setting billing configuration for a non-existent membership returns a clean error, not a crash', function () {
    $owner = User::factory()->create();

    expect(fn () => OrganizationMemberBillingConfigService::new()->setBillingConfig(
        OrganizationMemberBillingConfigDTO::new()->fromArray([
            'user' => $owner,
            'organizationMember' => null,
            'mode' => OrganizationMemberBillingModeEnum::retainer->value,
            'includeGroupTherapies' => true,
        ])
    ))->toThrow(OrganizationException::class);
});

test('changing billing configuration inserts a new row and preserves the old one, unmutated', function () {
    [$member, , $owner] = activeMembership();

    $original = OrganizationMemberBillingConfigService::new()->setBillingConfig(
        OrganizationMemberBillingConfigDTO::new()->fromArray([
            'user' => $owner,
            'organizationMember' => $member,
            'mode' => OrganizationMemberBillingModeEnum::retainer->value,
            'includeGroupTherapies' => true,
        ])
    );

    $changed = OrganizationMemberBillingConfigService::new()->setBillingConfig(
        OrganizationMemberBillingConfigDTO::new()->fromArray([
            'user' => $owner,
            'organizationMember' => $member,
            'mode' => OrganizationMemberBillingModeEnum::payPerUse->value,
            'per' => TherapyPerPaymentEnum::therapy->value,
            'includeGroupTherapies' => false,
        ])
    );

    expect($member->billingConfigs()->count())->toBe(2);
    expect($original->refresh()->mode)->toBe(OrganizationMemberBillingModeEnum::retainer->value);
    expect($changed->mode)->toBe(OrganizationMemberBillingModeEnum::payPerUse->value);
    expect($member->currentBillingConfig()->id)->toBe($changed->id);
});

test('two members of the same organization can have different billing modes simultaneously', function () {
    $organization = Organization::factory()->create(['is_provider' => false, 'is_consumer' => true, 'verified_at' => now()]);
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);

    $memberA = OrganizationMember::factory()->create(['organization_id' => $organization->id, 'user_id' => User::factory()]);
    $memberB = OrganizationMember::factory()->create(['organization_id' => $organization->id, 'user_id' => User::factory()]);

    OrganizationMemberBillingConfigService::new()->setBillingConfig(
        OrganizationMemberBillingConfigDTO::new()->fromArray([
            'user' => $owner,
            'organizationMember' => $memberA,
            'mode' => OrganizationMemberBillingModeEnum::retainer->value,
            'includeGroupTherapies' => true,
        ])
    );

    OrganizationMemberBillingConfigService::new()->setBillingConfig(
        OrganizationMemberBillingConfigDTO::new()->fromArray([
            'user' => $owner,
            'organizationMember' => $memberB,
            'mode' => OrganizationMemberBillingModeEnum::payPerUse->value,
            'per' => TherapyPerPaymentEnum::session->value,
            'includeGroupTherapies' => false,
        ])
    );

    expect($memberA->currentBillingConfig()->mode)->toBe(OrganizationMemberBillingModeEnum::retainer->value);
    expect($memberB->currentBillingConfig()->mode)->toBe(OrganizationMemberBillingModeEnum::payPerUse->value);
});
