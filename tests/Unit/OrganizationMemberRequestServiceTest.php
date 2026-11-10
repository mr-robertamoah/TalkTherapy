<?php

use App\DTOs\OrganizationDTO;
use App\DTOs\OrganizationMemberRequestDTO;
use App\DTOs\RequestResponseDTO;
use App\Enums\OrganizationAdminRoleEnum;
use App\Enums\OrganizationMemberSourceEnum;
use App\Enums\OrganizationMemberStatusEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Exceptions\CannotRespondToRequestException;
use App\Exceptions\OrganizationException;
use App\Exceptions\UserDoesNotExistException;
use App\Http\Resources\OrganizationRequestResource;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Services\OrganizationMemberRequestService;
use App\Services\OrganizationService;
use App\Services\RequestService;

function verifiedConsumerOrganization(array $overrides = []): Organization
{
    return Organization::factory()->create(array_merge([
        'is_provider' => false,
        'is_consumer' => true,
        'verified_at' => now(),
    ], $overrides));
}

function consumerOrgOwner(Organization $organization): User
{
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);

    return $owner;
}

test('an org admin can invite a user to a verified consumer organization', function () {
    $organization = verifiedConsumerOrganization();
    $owner = consumerOrgOwner($organization);
    $invitee = User::factory()->create();

    $request = OrganizationMemberRequestService::new()->inviteMember(
        OrganizationMemberRequestDTO::new()->fromArray([
            'user' => $owner,
            'organization' => $organization,
            'member' => $invitee,
        ])
    );

    expect($request->type)->toBe(RequestTypeEnum::organizationMemberInvite->value);
    expect($request->status)->toBe(RequestStatusEnum::pending->value);
    expect($request->from_type)->toBe(Organization::class);
    expect($request->to_id)->toBe($invitee->id);
});

test('a non-admin cannot invite a user to an organization', function () {
    $organization = verifiedConsumerOrganization();
    $outsider = User::factory()->create();
    $invitee = User::factory()->create();

    expect(fn () => OrganizationMemberRequestService::new()->inviteMember(
        OrganizationMemberRequestDTO::new()->fromArray([
            'user' => $outsider,
            'organization' => $organization,
            'member' => $invitee,
        ])
    ))->toThrow(OrganizationException::class);
});

test('a user cannot be invited to an unverified organization', function () {
    $organization = Organization::factory()->create(['is_provider' => false, 'is_consumer' => true, 'verified_at' => null]);
    $owner = consumerOrgOwner($organization);
    $invitee = User::factory()->create();

    expect(fn () => OrganizationMemberRequestService::new()->inviteMember(
        OrganizationMemberRequestDTO::new()->fromArray([
            'user' => $owner,
            'organization' => $organization,
            'member' => $invitee,
        ])
    ))->toThrow(OrganizationException::class);
});

test('a user cannot be invited to a non-consumer organization', function () {
    $organization = verifiedConsumerOrganization(['is_provider' => true, 'is_consumer' => false]);
    $owner = consumerOrgOwner($organization);
    $invitee = User::factory()->create();

    expect(fn () => OrganizationMemberRequestService::new()->inviteMember(
        OrganizationMemberRequestDTO::new()->fromArray([
            'user' => $owner,
            'organization' => $organization,
            'member' => $invitee,
        ])
    ))->toThrow(OrganizationException::class);
});

test('a user can self-apply to a verified consumer organization with self-apply enabled', function () {
    $organization = verifiedConsumerOrganization(['self_apply_enabled' => true]);
    $applicant = User::factory()->create();

    $request = OrganizationMemberRequestService::new()->applyAsMember(
        OrganizationMemberRequestDTO::new()->fromArray([
            'user' => $applicant,
            'organization' => $organization,
            'member' => $applicant,
        ])
    );

    expect($request->type)->toBe(RequestTypeEnum::organizationMemberApplication->value);
    expect($request->from_type)->toBe(User::class);
    expect($request->to_type)->toBe(Organization::class);
    expect($request->to_id)->toBe($organization->id);
});

// SCRUM-179: extended to also cover a nonexistent organization -- it previously 404'd via a
// standalone EnsureOrganizationExistsAction call ahead of this same generic-422 mitigation,
// reopening a narrower existence-only oracle SCRUM-170/178 had already closed elsewhere.
test('self-applying is rejected identically for a disabled self-apply org, an ineligible org, or a nonexistent org', function () {
    $disabledSelfApply = verifiedConsumerOrganization(['self_apply_enabled' => false]);
    $unverified = Organization::factory()->create(['is_provider' => false, 'is_consumer' => true, 'self_apply_enabled' => true, 'verified_at' => null]);

    $messageFor = function (?Organization $organization) {
        try {
            OrganizationMemberRequestService::new()->applyAsMember(
                OrganizationMemberRequestDTO::new()->fromArray([
                    'user' => $user = User::factory()->create(),
                    'organization' => $organization,
                    'member' => $user,
                ])
            );
        } catch (OrganizationException $e) {
            return $e->getMessage();
        }

        return null;
    };

    $disabledMessage = $messageFor($disabledSelfApply);
    $unverifiedMessage = $messageFor($unverified);
    $nonexistentMessage = $messageFor(null);

    expect($disabledMessage)->not->toBeNull();
    expect($disabledMessage)->toBe($unverifiedMessage);
    expect($disabledMessage)->toBe($nonexistentMessage);
});

test('enabling self-apply through the organization update flow allows a subsequent self-application', function () {
    $organization = verifiedConsumerOrganization(['self_apply_enabled' => false]);
    $owner = consumerOrgOwner($organization);
    $applicant = User::factory()->create();

    expect(fn () => OrganizationMemberRequestService::new()->applyAsMember(
        OrganizationMemberRequestDTO::new()->fromArray([
            'user' => $applicant,
            'organization' => $organization,
            'member' => $applicant,
        ])
    ))->toThrow(OrganizationException::class);

    OrganizationService::new()->updateOrganization(
        OrganizationDTO::new()->fromArray([
            'user' => $owner,
            'organization' => $organization,
            'selfApplyEnabled' => true,
        ])
    );

    $request = OrganizationMemberRequestService::new()->applyAsMember(
        OrganizationMemberRequestDTO::new()->fromArray([
            'user' => $applicant,
            'organization' => $organization->refresh(),
            'member' => $applicant,
        ])
    );

    expect($request->status)->toBe(RequestStatusEnum::pending->value);
});

test('a second pending request between the same organization and user is rejected regardless of direction', function () {
    $organization = verifiedConsumerOrganization(['self_apply_enabled' => true]);
    $owner = consumerOrgOwner($organization);
    $user = User::factory()->create();

    OrganizationMemberRequestService::new()->inviteMember(
        OrganizationMemberRequestDTO::new()->fromArray([
            'user' => $owner,
            'organization' => $organization,
            'member' => $user,
        ])
    );

    expect(fn () => OrganizationMemberRequestService::new()->applyAsMember(
        OrganizationMemberRequestDTO::new()->fromArray([
            'user' => $user,
            'organization' => $organization,
            'member' => $user,
        ])
    ))->toThrow(OrganizationException::class);
});

test('accepting an invite creates an active membership with source invited', function () {
    $organization = verifiedConsumerOrganization();
    $owner = consumerOrgOwner($organization);
    $invitee = User::factory()->create();

    $inviteRequest = OrganizationMemberRequestService::new()->inviteMember(
        OrganizationMemberRequestDTO::new()->fromArray([
            'user' => $owner,
            'organization' => $organization,
            'member' => $invitee,
        ])
    );

    $resource = RequestService::new()->respondToRequest(
        RequestResponseDTO::new()->fromArray([
            'user' => $invitee,
            'request' => $inviteRequest,
            'response' => 'accepted',
        ])
    );

    expect($resource)->toBeInstanceOf(OrganizationRequestResource::class);

    $member = OrganizationMember::query()
        ->where('organization_id', $organization->id)
        ->where('user_id', $invitee->id)
        ->first();

    expect($member)->not->toBeNull();
    expect($member->status)->toBe(OrganizationMemberStatusEnum::active->value);
    expect($member->source)->toBe(OrganizationMemberSourceEnum::invited->value);
});

test('an org admin can accept a member application addressed to the organization itself', function () {
    $organization = verifiedConsumerOrganization(['self_apply_enabled' => true]);
    $owner = consumerOrgOwner($organization);
    $applicant = User::factory()->create();

    $applicationRequest = OrganizationMemberRequestService::new()->applyAsMember(
        OrganizationMemberRequestDTO::new()->fromArray([
            'user' => $applicant,
            'organization' => $organization,
            'member' => $applicant,
        ])
    );

    RequestService::new()->respondToRequest(
        RequestResponseDTO::new()->fromArray([
            'user' => $owner,
            'request' => $applicationRequest,
            'response' => 'accepted',
        ])
    );

    $member = OrganizationMember::query()
        ->where('organization_id', $organization->id)
        ->where('user_id', $applicant->id)
        ->first();

    expect($member->source)->toBe(OrganizationMemberSourceEnum::applied->value);
});

test('a user who does not administer the organization cannot respond to a member application', function () {
    $organization = verifiedConsumerOrganization(['self_apply_enabled' => true]);
    consumerOrgOwner($organization);
    $applicant = User::factory()->create();

    $applicationRequest = OrganizationMemberRequestService::new()->applyAsMember(
        OrganizationMemberRequestDTO::new()->fromArray([
            'user' => $applicant,
            'organization' => $organization,
            'member' => $applicant,
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

test('a user can be an active member of multiple organizations at once', function () {
    $organizationA = verifiedConsumerOrganization();
    $organizationB = verifiedConsumerOrganization();
    $ownerA = consumerOrgOwner($organizationA);
    $ownerB = consumerOrgOwner($organizationB);
    $user = User::factory()->create();

    foreach ([[$organizationA, $ownerA], [$organizationB, $ownerB]] as [$organization, $owner]) {
        $inviteRequest = OrganizationMemberRequestService::new()->inviteMember(
            OrganizationMemberRequestDTO::new()->fromArray([
                'user' => $owner,
                'organization' => $organization,
                'member' => $user,
            ])
        );

        RequestService::new()->respondToRequest(
            RequestResponseDTO::new()->fromArray([
                'user' => $user,
                'request' => $inviteRequest,
                'response' => 'accepted',
            ])
        );
    }

    expect($user->organizationMemberships()->count())->toBe(2);
});

test('accepting a member application for an organization that is no longer eligible is rejected', function () {
    $organization = verifiedConsumerOrganization(['self_apply_enabled' => true]);
    $owner = consumerOrgOwner($organization);
    $applicant = User::factory()->create();

    $applicationRequest = OrganizationMemberRequestService::new()->applyAsMember(
        OrganizationMemberRequestDTO::new()->fromArray([
            'user' => $applicant,
            'organization' => $organization,
            'member' => $applicant,
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

test('inviting a member to a non-existent organization returns a clean error, not a crash', function () {
    $owner = User::factory()->create();
    $invitee = User::factory()->create();

    expect(fn () => OrganizationMemberRequestService::new()->inviteMember(
        OrganizationMemberRequestDTO::new()->fromArray([
            'user' => $owner,
            'organization' => null,
            'member' => $invitee,
        ])
    ))->toThrow(OrganizationException::class);
});

// SCRUM-178: a nonexistent organization and a real one the caller cannot administer previously
// threw different exceptions (a distinct 404 vs this action's own 403) -- the same oracle
// SCRUM-170 closed on the read-only org endpoints, closed here the same way.
test('inviting a member to a nonexistent organization and a real one the caller cannot administer fail identically', function () {
    $organization = verifiedConsumerOrganization();
    $outsider = User::factory()->create();
    $invitee = User::factory()->create();

    $message = 'You are not authorized to invite members to this organization.';

    expect(fn () => OrganizationMemberRequestService::new()->inviteMember(
        OrganizationMemberRequestDTO::new()->fromArray([
            'user' => $outsider,
            'organization' => null,
            'member' => $invitee,
        ])
    ))->toThrow(OrganizationException::class, $message);

    expect(fn () => OrganizationMemberRequestService::new()->inviteMember(
        OrganizationMemberRequestDTO::new()->fromArray([
            'user' => $outsider,
            'organization' => $organization,
            'member' => $invitee,
        ])
    ))->toThrow(OrganizationException::class, $message);
});

// Parity with OrganizationCounsellorRequestServiceTest's equivalent case -- the service layer
// must not depend solely on the FormRequest's `exists:users,id` rule to avoid an ungraceful
// crash (reviewer-found gap).
test('inviting a non-existent member returns a clean error, not a crash', function () {
    $organization = verifiedConsumerOrganization();
    $owner = consumerOrgOwner($organization);

    expect(fn () => OrganizationMemberRequestService::new()->inviteMember(
        OrganizationMemberRequestDTO::new()->fromArray([
            'user' => $owner,
            'organization' => $organization,
            'member' => null,
        ])
    ))->toThrow(UserDoesNotExistException::class);
});
