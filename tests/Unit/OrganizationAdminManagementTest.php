<?php

use App\DTOs\OrganizationAdminDTO;
use App\Enums\OrganizationAdminRoleEnum;
use App\Exceptions\OrganizationException;
use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationAdminService;

function organizationWithOwner(): array
{
    $organization = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);

    return [$organization, $owner];
}

test('an owner can add a new admin, defaulting to the admin role', function () {
    [$organization, $owner] = organizationWithOwner();
    $newAdmin = User::factory()->create();

    OrganizationAdminService::new()->addAdmin(
        OrganizationAdminDTO::new()->fromArray(['user' => $owner, 'organization' => $organization, 'admin' => $newAdmin])
    );

    expect($organization->isAdministeredBy($newAdmin))->toBeTrue();
    expect($organization->admins()->whereKey($newAdmin->id)->first()->pivot->role)->toBe(OrganizationAdminRoleEnum::admin->value);
});

test('an owner can add a new admin as a co-owner', function () {
    [$organization, $owner] = organizationWithOwner();
    $newOwner = User::factory()->create();

    OrganizationAdminService::new()->addAdmin(
        OrganizationAdminDTO::new()->fromArray([
            'user' => $owner,
            'organization' => $organization,
            'admin' => $newOwner,
            'role' => OrganizationAdminRoleEnum::owner->value,
        ])
    );

    expect($organization->admins()->whereKey($newOwner->id)->first()->pivot->role)->toBe(OrganizationAdminRoleEnum::owner->value);
});

test('adding a user who is already an admin is rejected', function () {
    [$organization, $owner] = organizationWithOwner();

    expect(fn () => OrganizationAdminService::new()->addAdmin(
        OrganizationAdminDTO::new()->fromArray(['user' => $owner, 'organization' => $organization, 'admin' => $owner])
    ))->toThrow(OrganizationException::class);
});

test('a plain admin (not owner) cannot add another admin', function () {
    [$organization, $owner] = organizationWithOwner();
    $plainAdmin = User::factory()->create();
    $organization->admins()->attach($plainAdmin->id, ['role' => OrganizationAdminRoleEnum::admin->value]);
    $newAdmin = User::factory()->create();

    expect(fn () => OrganizationAdminService::new()->addAdmin(
        OrganizationAdminDTO::new()->fromArray(['user' => $plainAdmin, 'organization' => $organization, 'admin' => $newAdmin])
    ))->toThrow(OrganizationException::class);

    expect($organization->isAdministeredBy($newAdmin))->toBeFalse();
});

test('an owner can remove a plain admin', function () {
    [$organization, $owner] = organizationWithOwner();
    $plainAdmin = User::factory()->create();
    $organization->admins()->attach($plainAdmin->id, ['role' => OrganizationAdminRoleEnum::admin->value]);

    OrganizationAdminService::new()->removeAdmin(
        OrganizationAdminDTO::new()->fromArray(['user' => $owner, 'organization' => $organization, 'admin' => $plainAdmin])
    );

    expect($organization->isAdministeredBy($plainAdmin))->toBeFalse();
});

test('an owner can remove a co-owner as long as another owner remains', function () {
    [$organization, $owner] = organizationWithOwner();
    $coOwner = User::factory()->create();
    $organization->admins()->attach($coOwner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);

    OrganizationAdminService::new()->removeAdmin(
        OrganizationAdminDTO::new()->fromArray(['user' => $owner, 'organization' => $organization, 'admin' => $coOwner])
    );

    expect($organization->isAdministeredBy($coOwner))->toBeFalse();
});

test('removing the last remaining owner is rejected', function () {
    [$organization, $owner] = organizationWithOwner();

    expect(fn () => OrganizationAdminService::new()->removeAdmin(
        OrganizationAdminDTO::new()->fromArray(['user' => $owner, 'organization' => $organization, 'admin' => $owner])
    ))->toThrow(OrganizationException::class);

    expect($organization->isAdministeredBy($owner))->toBeTrue();
});

test('demoting the last remaining owner to admin is rejected', function () {
    [$organization, $owner] = organizationWithOwner();

    expect(fn () => OrganizationAdminService::new()->updateAdminRole(
        OrganizationAdminDTO::new()->fromArray([
            'user' => $owner,
            'organization' => $organization,
            'admin' => $owner,
            'role' => OrganizationAdminRoleEnum::admin->value,
        ])
    ))->toThrow(OrganizationException::class);

    expect($organization->admins()->whereKey($owner->id)->first()->pivot->role)->toBe(OrganizationAdminRoleEnum::owner->value);
});

test('demoting one of two owners succeeds', function () {
    [$organization, $owner] = organizationWithOwner();
    $coOwner = User::factory()->create();
    $organization->admins()->attach($coOwner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);

    OrganizationAdminService::new()->updateAdminRole(
        OrganizationAdminDTO::new()->fromArray([
            'user' => $owner,
            'organization' => $organization,
            'admin' => $coOwner,
            'role' => OrganizationAdminRoleEnum::admin->value,
        ])
    );

    expect($organization->admins()->whereKey($coOwner->id)->first()->pivot->role)->toBe(OrganizationAdminRoleEnum::admin->value);
});

test('an owner can promote a plain admin to owner', function () {
    [$organization, $owner] = organizationWithOwner();
    $plainAdmin = User::factory()->create();
    $organization->admins()->attach($plainAdmin->id, ['role' => OrganizationAdminRoleEnum::admin->value]);

    OrganizationAdminService::new()->updateAdminRole(
        OrganizationAdminDTO::new()->fromArray([
            'user' => $owner,
            'organization' => $organization,
            'admin' => $plainAdmin,
            'role' => OrganizationAdminRoleEnum::owner->value,
        ])
    );

    expect($organization->admins()->whereKey($plainAdmin->id)->first()->pivot->role)->toBe(OrganizationAdminRoleEnum::owner->value);
});

test('a plain admin (not owner) cannot remove or promote/demote another admin', function () {
    [$organization, $owner] = organizationWithOwner();
    $plainAdmin = User::factory()->create();
    $organization->admins()->attach($plainAdmin->id, ['role' => OrganizationAdminRoleEnum::admin->value]);
    $anotherAdmin = User::factory()->create();
    $organization->admins()->attach($anotherAdmin->id, ['role' => OrganizationAdminRoleEnum::admin->value]);

    expect(fn () => OrganizationAdminService::new()->removeAdmin(
        OrganizationAdminDTO::new()->fromArray(['user' => $plainAdmin, 'organization' => $organization, 'admin' => $anotherAdmin])
    ))->toThrow(OrganizationException::class);

    expect(fn () => OrganizationAdminService::new()->updateAdminRole(
        OrganizationAdminDTO::new()->fromArray([
            'user' => $plainAdmin,
            'organization' => $organization,
            'admin' => $anotherAdmin,
            'role' => OrganizationAdminRoleEnum::owner->value,
        ])
    ))->toThrow(OrganizationException::class);
});

test('removing or promoting a user who is not an admin of the organization is rejected', function () {
    [$organization, $owner] = organizationWithOwner();
    $outsider = User::factory()->create();

    expect(fn () => OrganizationAdminService::new()->removeAdmin(
        OrganizationAdminDTO::new()->fromArray(['user' => $owner, 'organization' => $organization, 'admin' => $outsider])
    ))->toThrow(OrganizationException::class);

    expect(fn () => OrganizationAdminService::new()->updateAdminRole(
        OrganizationAdminDTO::new()->fromArray([
            'user' => $owner,
            'organization' => $organization,
            'admin' => $outsider,
            'role' => OrganizationAdminRoleEnum::admin->value,
        ])
    ))->toThrow(OrganizationException::class);
});
