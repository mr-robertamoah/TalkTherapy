<?php

use App\DTOs\OrganizationCounsellorRequestDTO;
use App\DTOs\OrganizationDTO;
use App\DTOs\RequestResponseDTO;
use App\Enums\AdministratorTypeEnum;
use App\Enums\OrganizationAdminRoleEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Exceptions\BadRequestException;
use App\Exceptions\CannotRespondToRequestException;
use App\Exceptions\CounsellorNotFoundException;
use App\Exceptions\OrganizationException;
use App\Http\Resources\OrganizationRequestResource;
use App\Models\Counsellor;
use App\Models\Organization;
use App\Models\Request;
use App\Models\User;
use App\Services\OrganizationCounsellorRequestService;
use App\Services\OrganizationService;
use App\Services\RequestService;

function verifiedProviderOrganization(array $overrides = []): Organization
{
    return Organization::factory()->create(array_merge([
        'is_provider' => true,
        'verified_at' => now(),
    ], $overrides));
}

function organizationOwner(Organization $organization): User
{
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);

    return $owner;
}

test('an org admin can invite a counsellor to a verified provider organization', function () {
    $organization = verifiedProviderOrganization();
    $owner = organizationOwner($organization);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);

    $request = OrganizationCounsellorRequestService::new()->inviteCounsellor(
        OrganizationCounsellorRequestDTO::new()->fromArray([
            'user' => $owner,
            'organization' => $organization,
            'counsellor' => $counsellor,
        ])
    );

    expect($request->type)->toBe(RequestTypeEnum::organizationCounsellorInvite->value);
    expect($request->status)->toBe(RequestStatusEnum::pending->value);
    expect($request->from_type)->toBe(Organization::class);
    expect($request->to_id)->toBe($counsellor->id);
});

test('a non-admin cannot invite a counsellor to an organization', function () {
    $organization = verifiedProviderOrganization();
    $outsider = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);

    expect(fn () => OrganizationCounsellorRequestService::new()->inviteCounsellor(
        OrganizationCounsellorRequestDTO::new()->fromArray([
            'user' => $outsider,
            'organization' => $organization,
            'counsellor' => $counsellor,
        ])
    ))->toThrow(OrganizationException::class);
});

test('a counsellor cannot be invited to an unverified organization', function () {
    $organization = Organization::factory()->create(['is_provider' => true, 'verified_at' => null]);
    $owner = organizationOwner($organization);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);

    expect(fn () => OrganizationCounsellorRequestService::new()->inviteCounsellor(
        OrganizationCounsellorRequestDTO::new()->fromArray([
            'user' => $owner,
            'organization' => $organization,
            'counsellor' => $counsellor,
        ])
    ))->toThrow(OrganizationException::class);
});

test('a counsellor cannot be invited to a non-provider organization', function () {
    $organization = verifiedProviderOrganization(['is_provider' => false, 'is_consumer' => true]);
    $owner = organizationOwner($organization);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);

    expect(fn () => OrganizationCounsellorRequestService::new()->inviteCounsellor(
        OrganizationCounsellorRequestDTO::new()->fromArray([
            'user' => $owner,
            'organization' => $organization,
            'counsellor' => $counsellor,
        ])
    ))->toThrow(OrganizationException::class);
});

test('a counsellor can apply to affiliate with a verified provider organization', function () {
    $organization = verifiedProviderOrganization();
    $applicantUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $applicantUser->id]);

    $request = OrganizationCounsellorRequestService::new()->applyAsCounsellor(
        OrganizationCounsellorRequestDTO::new()->fromArray([
            'user' => $applicantUser,
            'organization' => $organization,
            'counsellor' => $counsellor,
        ])
    );

    expect($request->type)->toBe(RequestTypeEnum::organizationCounsellorApplication->value);
    expect($request->from_type)->toBe(Counsellor::class);
    expect($request->to_type)->toBe(Organization::class);
    expect($request->to_id)->toBe($organization->id);
});

test('a user cannot apply on behalf of a counsellor profile that is not their own', function () {
    $organization = verifiedProviderOrganization();
    $someoneElse = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);

    expect(fn () => OrganizationCounsellorRequestService::new()->applyAsCounsellor(
        OrganizationCounsellorRequestDTO::new()->fromArray([
            'user' => $someoneElse,
            'organization' => $organization,
            'counsellor' => $counsellor,
        ])
    ))->toThrow(OrganizationException::class);
});

test('a second pending request between the same organization and counsellor is rejected regardless of direction', function () {
    $organization = verifiedProviderOrganization();
    $owner = organizationOwner($organization);
    $applicantUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $applicantUser->id]);

    OrganizationCounsellorRequestService::new()->inviteCounsellor(
        OrganizationCounsellorRequestDTO::new()->fromArray([
            'user' => $owner,
            'organization' => $organization,
            'counsellor' => $counsellor,
        ])
    );

    expect(fn () => OrganizationCounsellorRequestService::new()->applyAsCounsellor(
        OrganizationCounsellorRequestDTO::new()->fromArray([
            'user' => $applicantUser,
            'organization' => $organization,
            'counsellor' => $counsellor,
        ])
    ))->toThrow(OrganizationException::class);
});

test('an org admin can accept a counsellor application addressed to the organization itself', function () {
    $organization = verifiedProviderOrganization();
    $owner = organizationOwner($organization);
    $applicantUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $applicantUser->id]);

    $applicationRequest = OrganizationCounsellorRequestService::new()->applyAsCounsellor(
        OrganizationCounsellorRequestDTO::new()->fromArray([
            'user' => $applicantUser,
            'organization' => $organization,
            'counsellor' => $counsellor,
        ])
    );

    $resource = RequestService::new()->respondToRequest(
        RequestResponseDTO::new()->fromArray([
            'user' => $owner,
            'request' => $applicationRequest,
            'response' => 'accepted',
        ])
    );

    expect($resource)->toBeInstanceOf(OrganizationRequestResource::class);
    expect($applicationRequest->refresh()->status)->toBe(RequestStatusEnum::accepted->value);
});

// SCRUM-171: pins that the generic pending-status guard applies uniformly to this request type
// too, not just group-therapy membership requests (where the same guard is also tested).
test('an org admin cannot respond to a counsellor application that was already accepted', function () {
    $organization = verifiedProviderOrganization();
    $owner = organizationOwner($organization);
    $applicantUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $applicantUser->id]);

    $applicationRequest = OrganizationCounsellorRequestService::new()->applyAsCounsellor(
        OrganizationCounsellorRequestDTO::new()->fromArray([
            'user' => $applicantUser,
            'organization' => $organization,
            'counsellor' => $counsellor,
        ])
    );
    $applicationRequest->update(['status' => RequestStatusEnum::accepted->value]);

    expect(fn () => RequestService::new()->respondToRequest(
        RequestResponseDTO::new()->fromArray([
            'user' => $owner,
            'request' => $applicationRequest,
            'response' => 'rejected',
        ])
    ))->toThrow(BadRequestException::class);

    expect($applicationRequest->fresh()->status)->toBe(RequestStatusEnum::accepted->value);
});

test('a user who does not administer the organization cannot respond to a counsellor application', function () {
    $organization = verifiedProviderOrganization();
    organizationOwner($organization);
    $applicantUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $applicantUser->id]);

    $applicationRequest = OrganizationCounsellorRequestService::new()->applyAsCounsellor(
        OrganizationCounsellorRequestDTO::new()->fromArray([
            'user' => $applicantUser,
            'organization' => $organization,
            'counsellor' => $counsellor,
        ])
    );

    $outsider = User::factory()->create();

    expect(fn () => RequestService::new()->respondToRequest(
        RequestResponseDTO::new()->fromArray([
            'user' => $outsider,
            'request' => $applicationRequest,
            'response' => 'accepted',
        ])
    ))->toThrow(CannotRespondToRequestException::class);
});

test('the invited counsellor can accept an organization invite addressed to their counsellor profile', function () {
    $organization = verifiedProviderOrganization();
    $owner = organizationOwner($organization);
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);

    $inviteRequest = OrganizationCounsellorRequestService::new()->inviteCounsellor(
        OrganizationCounsellorRequestDTO::new()->fromArray([
            'user' => $owner,
            'organization' => $organization,
            'counsellor' => $counsellor,
        ])
    );

    $resource = RequestService::new()->respondToRequest(
        RequestResponseDTO::new()->fromArray([
            'user' => $counsellorUser,
            'request' => $inviteRequest,
            'response' => 'accepted',
        ])
    );

    expect($resource)->toBeInstanceOf(OrganizationRequestResource::class);
    expect($inviteRequest->refresh()->status)->toBe(RequestStatusEnum::accepted->value);
});

// SCRUM-120 review fix: previously threw an uncaught Error (calling isAdministeredBy() on
// null) instead of a clean, catchable exception.
test('inviting a counsellor to a non-existent organization returns a clean error, not a crash', function () {
    $owner = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);

    expect(fn () => OrganizationCounsellorRequestService::new()->inviteCounsellor(
        OrganizationCounsellorRequestDTO::new()->fromArray([
            'user' => $owner,
            'organization' => null,
            'counsellor' => $counsellor,
        ])
    ))->toThrow(OrganizationException::class);
});

test('inviting a non-existent counsellor returns a clean error, not a crash', function () {
    $organization = verifiedProviderOrganization();
    $owner = organizationOwner($organization);

    expect(fn () => OrganizationCounsellorRequestService::new()->inviteCounsellor(
        OrganizationCounsellorRequestDTO::new()->fromArray([
            'user' => $owner,
            'organization' => $organization,
            'counsellor' => null,
        ])
    ))->toThrow(CounsellorNotFoundException::class);
});

test('applying to a non-existent organization returns a clean error, not a crash', function () {
    $applicantUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $applicantUser->id]);

    expect(fn () => OrganizationCounsellorRequestService::new()->applyAsCounsellor(
        OrganizationCounsellorRequestDTO::new()->fromArray([
            'user' => $applicantUser,
            'organization' => null,
            'counsellor' => $counsellor,
        ])
    ))->toThrow(OrganizationException::class);
});

// SCRUM-120 security review: applying to an unverified org and applying to a non-provider org
// must return the SAME message -- OrganizationController::show() restricts org details to that
// org's own admins, so a counsellor-facing endpoint can't let distinguishable errors be used to
// probe an arbitrary organization's verification/provider status.
test('applying to an unverified or non-provider organization gives the same generic message either way', function () {
    $unverified = Organization::factory()->create(['is_provider' => true, 'verified_at' => null]);
    $nonProvider = verifiedProviderOrganization(['is_provider' => false, 'is_consumer' => true]);

    $applicantUserA = User::factory()->create();
    $counsellorA = Counsellor::factory()->create(['user_id' => $applicantUserA->id]);
    $applicantUserB = User::factory()->create();
    $counsellorB = Counsellor::factory()->create(['user_id' => $applicantUserB->id]);

    $messageFor = function (Organization $organization, User $user, Counsellor $counsellor) {
        try {
            OrganizationCounsellorRequestService::new()->applyAsCounsellor(
                OrganizationCounsellorRequestDTO::new()->fromArray([
                    'user' => $user,
                    'organization' => $organization,
                    'counsellor' => $counsellor,
                ])
            );
        } catch (OrganizationException $e) {
            return $e->getMessage();
        }

        return null;
    };

    $unverifiedMessage = $messageFor($unverified, $applicantUserA, $counsellorA);
    $nonProviderMessage = $messageFor($nonProvider, $applicantUserB, $counsellorB);

    expect($unverifiedMessage)->not->toBeNull();
    expect($unverifiedMessage)->toBe($nonProviderMessage);
});

test('accepting a counsellor application for an organization that is no longer eligible is rejected', function () {
    $organization = verifiedProviderOrganization();
    $owner = organizationOwner($organization);
    $applicantUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $applicantUser->id]);

    $applicationRequest = OrganizationCounsellorRequestService::new()->applyAsCounsellor(
        OrganizationCounsellorRequestDTO::new()->fromArray([
            'user' => $applicantUser,
            'organization' => $organization,
            'counsellor' => $counsellor,
        ])
    );

    $organization->verified_at = null;
    $organization->save();

    expect(fn () => RequestService::new()->respondToRequest(
        RequestResponseDTO::new()->fromArray([
            'user' => $owner,
            'request' => $applicationRequest,
            'response' => 'accepted',
        ])
    ))->toThrow(OrganizationException::class);

    expect($applicationRequest->refresh()->status)->toBe(RequestStatusEnum::pending->value);
});

test('rejecting a stale counsellor application still works even if the organization is no longer eligible', function () {
    $organization = verifiedProviderOrganization();
    $owner = organizationOwner($organization);
    $applicantUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $applicantUser->id]);

    $applicationRequest = OrganizationCounsellorRequestService::new()->applyAsCounsellor(
        OrganizationCounsellorRequestDTO::new()->fromArray([
            'user' => $applicantUser,
            'organization' => $organization,
            'counsellor' => $counsellor,
        ])
    );

    $organization->verified_at = null;
    $organization->save();

    RequestService::new()->respondToRequest(
        RequestResponseDTO::new()->fromArray([
            'user' => $owner,
            'request' => $applicationRequest,
            'response' => 'rejected',
        ])
    );

    expect($applicationRequest->refresh()->status)->toBe(RequestStatusEnum::rejected->value);
});

// Regression for the latent SCRUM-119 gap: responding to an organization-verification request
// through the real, full RequestService::respondToRequest() path (not a hand-built Action call)
// must resolve a correct resource, not silently degrade via AdminCounsellorVerificationRequestResource.
test('responding to an organization verification request through the real endpoint path resolves a correct resource', function () {
    $user = User::factory()->create();
    $superAdminUser = User::factory()->create();
    $superAdminUser->administrator()->create(['type' => AdministratorTypeEnum::super->value]);

    $organization = OrganizationService::new()->createOrganization(
        OrganizationDTO::new()->fromArray([
            'user' => $user,
            'name' => 'Verify Me Org',
            'registrationNumber' => 'REG-VERIFY-1',
            'isProvider' => true,
            'isConsumer' => false,
        ])
    );

    $verificationRequest = Request::query()->whereFor($organization)->first();

    $resource = RequestService::new()->respondToRequest(
        RequestResponseDTO::new()->fromArray([
            'user' => $superAdminUser,
            'request' => $verificationRequest,
            'response' => 'accepted',
        ])
    );

    expect($resource)->toBeInstanceOf(OrganizationRequestResource::class);
    expect($organization->refresh()->isVerified())->toBeTrue();
});
