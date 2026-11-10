<?php

use App\Actions\Request\RespondToRequestAction;
use App\DTOs\GetOrganizationDirectoryDTO;
use App\DTOs\OrganizationDTO;
use App\DTOs\RequestResponseDTO;
use App\Enums\AdministratorTypeEnum;
use App\Enums\OrganizationAdminRoleEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Exceptions\OrganizationException;
use App\Models\Organization;
use App\Models\Request;
use App\Models\User;
use App\Services\OrganizationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function organizationCreationData(array $overrides = []): array
{
    return array_merge([
        'name' => 'Acme Wellness',
        'legalName' => 'Acme Wellness Ltd',
        'registrationNumber' => 'REG-12345678',
        'description' => 'A workplace wellness provider.',
        'email' => 'contact@acme.test',
        'phone' => '+233000000000',
        'isProvider' => true,
        'isConsumer' => false,
    ], $overrides);
}

test('a user can create an organization and becomes its owner admin', function () {
    $user = User::factory()->create();

    $organization = OrganizationService::new()->createOrganization(
        OrganizationDTO::new()->fromArray(array_merge(['user' => $user], organizationCreationData()))
    );

    expect($organization->exists)->toBeTrue();
    expect($organization->isVerified())->toBeFalse();
    expect($organization->isAdministeredBy($user))->toBeTrue();
    expect($organization->admins()->first()->pivot->role)->toBe(OrganizationAdminRoleEnum::owner->value);
});

test('creating an organization submits a pending verification request to a super admin', function () {
    $user = User::factory()->create();
    $superAdminUser = User::factory()->create();
    $superAdminUser->administrator()->create(['type' => AdministratorTypeEnum::super->value]);

    $organization = OrganizationService::new()->createOrganization(
        OrganizationDTO::new()->fromArray(array_merge(['user' => $user], organizationCreationData()))
    );

    expect($organization->hasPendingVerificationRequest())->toBeTrue();

    $request = Request::query()->whereFor($organization)->first();
    expect($request->type)->toBe(RequestTypeEnum::organization->value);
    expect($request->status)->toBe(RequestStatusEnum::pending->value);
    expect($request->to_id)->toBe($superAdminUser->id);
});

test('an organization cannot be created as neither provider nor consumer', function () {
    $user = User::factory()->create();

    expect(fn () => OrganizationService::new()->createOrganization(
        OrganizationDTO::new()->fromArray(array_merge(
            ['user' => $user],
            organizationCreationData(['isProvider' => false, 'isConsumer' => false])
        ))
    ))->toThrow(OrganizationException::class);
});

// The Action-level guard above is defense-in-depth -- this proves the DB-level CHECK
// constraint itself rejects the invariant violation, independent of Eloquent validation.
test('the database rejects an organization row with neither provider nor consumer set, even via a raw insert', function () {
    expect(fn () => DB::table('organizations')->insert([
        'name' => 'Bypasses Eloquent',
        'registration_number' => 'REG-RAW-1',
        'is_provider' => false,
        'is_consumer' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('an org admin can update the organization profile', function () {
    $owner = User::factory()->create();
    $organization = Organization::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);

    $updated = OrganizationService::new()->updateOrganization(
        OrganizationDTO::new()->fromArray([
            'user' => $owner,
            'organization' => $organization,
            'description' => 'Updated description',
        ])
    );

    expect($updated->description)->toBe('Updated description');
});

test('a user with no admin relationship to the organization cannot update it', function () {
    $organization = Organization::factory()->create();
    $outsider = User::factory()->create();

    expect(fn () => OrganizationService::new()->updateOrganization(
        OrganizationDTO::new()->fromArray([
            'user' => $outsider,
            'organization' => $organization,
            'description' => 'Should not apply',
        ])
    ))->toThrow(OrganizationException::class);
});

test('updating an organization to drop both provider and consumer flags is rejected', function () {
    $owner = User::factory()->create();
    $organization = Organization::factory()->create(['is_provider' => true, 'is_consumer' => false]);
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);

    expect(fn () => OrganizationService::new()->updateOrganization(
        OrganizationDTO::new()->fromArray([
            'user' => $owner,
            'organization' => $organization,
            'isProvider' => false,
        ])
    ))->toThrow(OrganizationException::class);
});

test('a user can be an admin of multiple organizations with a different role in each', function () {
    $user = User::factory()->create();
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $organizationA->admins()->attach($user->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
    $organizationB->admins()->attach($user->id, ['role' => OrganizationAdminRoleEnum::admin->value]);

    expect($user->isAdminOf($organizationA))->toBeTrue();
    expect($user->isAdminOf($organizationB))->toBeTrue();
    expect($user->administeredOrganizations()->find($organizationA->id)->pivot->role)->toBe(OrganizationAdminRoleEnum::owner->value);
    expect($user->administeredOrganizations()->find($organizationB->id)->pivot->role)->toBe(OrganizationAdminRoleEnum::admin->value);
});

test('an admin of one organization is not authorized to manage a different organization', function () {
    $adminOfA = User::factory()->create();
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();
    $organizationA->admins()->attach($adminOfA->id, ['role' => OrganizationAdminRoleEnum::owner->value]);

    expect(fn () => OrganizationService::new()->updateOrganization(
        OrganizationDTO::new()->fromArray([
            'user' => $adminOfA,
            'organization' => $organizationB,
            'description' => 'Should not apply',
        ])
    ))->toThrow(OrganizationException::class);
});

test('an org admin can view their organization', function () {
    $owner = User::factory()->create();
    $organization = Organization::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);

    $fetched = OrganizationService::new()->getOrganization(
        OrganizationDTO::new()->fromArray(['user' => $owner, 'organization' => $organization])
    );

    expect($fetched->is($organization))->toBeTrue();
});

test('a user with no admin relationship to the organization cannot view it', function () {
    $organization = Organization::factory()->create();
    $outsider = User::factory()->create();

    expect(fn () => OrganizationService::new()->getOrganization(
        OrganizationDTO::new()->fromArray(['user' => $outsider, 'organization' => $organization])
    ))->toThrow(OrganizationException::class);
});

test('verified_at cannot be set via mass assignment', function () {
    $organization = Organization::create([
        'name' => 'Acme Wellness',
        'registration_number' => 'REG-MASS-ASSIGN',
        'is_provider' => true,
        'verified_at' => now(),
    ]);

    expect($organization->refresh()->verified_at)->toBeNull();
});

test('a platform super admin accepting the verification request verifies the organization', function () {
    $user = User::factory()->create();
    $superAdminUser = User::factory()->create();
    $superAdminUser->administrator()->create(['type' => AdministratorTypeEnum::super->value]);

    $organization = OrganizationService::new()->createOrganization(
        OrganizationDTO::new()->fromArray(array_merge(['user' => $user], organizationCreationData()))
    );

    $request = Request::query()->whereFor($organization)->first();

    RespondToRequestAction::new()->execute(
        RequestResponseDTO::new()->fromArray([
            'user' => $superAdminUser,
            'request' => $request,
            'response' => 'accepted',
        ])
    );

    expect($organization->refresh()->isVerified())->toBeTrue();
});

// TT-6.6c (SCRUM-161): organization directory -- verified-only (2026-08-29 decision), any
// authenticated user, not just an org's own admins.

test('the directory only returns verified organizations', function () {
    $verified = Organization::factory()->create(['verified_at' => now()]);
    Organization::factory()->create(['verified_at' => null]);

    $directory = OrganizationService::new()->getOrganizationDirectory(
        GetOrganizationDirectoryDTO::new()->fromArray([])
    );

    expect($directory->total())->toBe(1);
    expect($directory->items()[0]->is($verified))->toBeTrue();
});

test('the directory can be filtered to provider organizations only', function () {
    $provider = Organization::factory()->create(['is_provider' => true, 'is_consumer' => false, 'verified_at' => now()]);
    Organization::factory()->create(['is_provider' => false, 'is_consumer' => true, 'verified_at' => now()]);

    $directory = OrganizationService::new()->getOrganizationDirectory(
        GetOrganizationDirectoryDTO::new()->fromArray(['isProvider' => true])
    );

    expect($directory->total())->toBe(1);
    expect($directory->items()[0]->is($provider))->toBeTrue();
});

test('the directory can be filtered to consumer organizations only', function () {
    Organization::factory()->create(['is_provider' => true, 'is_consumer' => false, 'verified_at' => now()]);
    $consumer = Organization::factory()->create(['is_provider' => false, 'is_consumer' => true, 'verified_at' => now()]);

    $directory = OrganizationService::new()->getOrganizationDirectory(
        GetOrganizationDirectoryDTO::new()->fromArray(['isConsumer' => true])
    );

    expect($directory->total())->toBe(1);
    expect($directory->items()[0]->is($consumer))->toBeTrue();
});

test('isProvider and isConsumer filters combine as AND, not OR', function () {
    $both = Organization::factory()->create(['is_provider' => true, 'is_consumer' => true, 'verified_at' => now()]);
    Organization::factory()->create(['is_provider' => true, 'is_consumer' => false, 'verified_at' => now()]);
    Organization::factory()->create(['is_provider' => false, 'is_consumer' => true, 'verified_at' => now()]);

    $directory = OrganizationService::new()->getOrganizationDirectory(
        GetOrganizationDirectoryDTO::new()->fromArray(['isProvider' => true, 'isConsumer' => true])
    );

    expect($directory->total())->toBe(1);
    expect($directory->items()[0]->is($both))->toBeTrue();
});
