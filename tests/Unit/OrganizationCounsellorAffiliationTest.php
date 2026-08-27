<?php

use App\DTOs\OrganizationCounsellorRequestDTO;
use App\DTOs\RequestResponseDTO;
use App\Enums\OrganizationAdminRoleEnum;
use App\Enums\OrganizationCounsellorSourceEnum;
use App\Enums\OrganizationCounsellorStatusEnum;
use App\Enums\RequestStatusEnum;
use App\Exceptions\OrganizationException;
use App\Models\Counsellor;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\Therapy;
use App\Models\User;
use App\Services\OrganizationCounsellorRequestService;
use App\Services\RequestService;

function verifiedProviderOrg(array $overrides = []): Organization
{
    return Organization::factory()->create(array_merge([
        'is_provider' => true,
        'verified_at' => now(),
    ], $overrides));
}

function orgOwner(Organization $organization): User
{
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);

    return $owner;
}

test('accepting an invite for a verified counsellor creates a pending affiliation with source invited', function () {
    $organization = verifiedProviderOrg();
    $owner = orgOwner($organization);
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id, 'verified_at' => now()]);

    $inviteRequest = OrganizationCounsellorRequestService::new()->inviteCounsellor(
        OrganizationCounsellorRequestDTO::new()->fromArray([
            'user' => $owner,
            'organization' => $organization,
            'counsellor' => $counsellor,
        ])
    );

    RequestService::new()->respondToRequest(
        RequestResponseDTO::new()->fromArray([
            'user' => $counsellorUser,
            'request' => $inviteRequest,
            'response' => 'accepted',
        ])
    );

    $affiliation = OrganizationCounsellor::query()
        ->where('organization_id', $organization->id)
        ->where('counsellor_id', $counsellor->id)
        ->first();

    expect($affiliation)->not->toBeNull();
    expect($affiliation->status)->toBe(OrganizationCounsellorStatusEnum::pending->value);
    expect($affiliation->source)->toBe(OrganizationCounsellorSourceEnum::invited->value);
});

test('accepting an application for a verified counsellor creates a pending affiliation with source applied', function () {
    $organization = verifiedProviderOrg();
    $owner = orgOwner($organization);
    $applicantUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $applicantUser->id, 'verified_at' => now()]);

    $applicationRequest = OrganizationCounsellorRequestService::new()->applyAsCounsellor(
        OrganizationCounsellorRequestDTO::new()->fromArray([
            'user' => $applicantUser,
            'organization' => $organization,
            'counsellor' => $counsellor,
        ])
    );

    RequestService::new()->respondToRequest(
        RequestResponseDTO::new()->fromArray([
            'user' => $owner,
            'request' => $applicationRequest,
            'response' => 'accepted',
        ])
    );

    $affiliation = OrganizationCounsellor::query()
        ->where('organization_id', $organization->id)
        ->where('counsellor_id', $counsellor->id)
        ->first();

    expect($affiliation)->not->toBeNull();
    expect($affiliation->status)->toBe(OrganizationCounsellorStatusEnum::pending->value);
    expect($affiliation->source)->toBe(OrganizationCounsellorSourceEnum::applied->value);
});

test('accepting an invite for an unverified counsellor is rejected and creates no affiliation', function () {
    $organization = verifiedProviderOrg();
    $owner = orgOwner($organization);
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id, 'verified_at' => null]);

    $inviteRequest = OrganizationCounsellorRequestService::new()->inviteCounsellor(
        OrganizationCounsellorRequestDTO::new()->fromArray([
            'user' => $owner,
            'organization' => $organization,
            'counsellor' => $counsellor,
        ])
    );

    expect(fn () => RequestService::new()->respondToRequest(
        RequestResponseDTO::new()->fromArray([
            'user' => $counsellorUser,
            'request' => $inviteRequest,
            'response' => 'accepted',
        ])
    ))->toThrow(OrganizationException::class);

    expect($inviteRequest->refresh()->status)->toBe(RequestStatusEnum::pending->value);
    expect(OrganizationCounsellor::query()->where('counsellor_id', $counsellor->id)->exists())->toBeFalse();
});

test('a counsellor can hold affiliations with multiple organizations at once', function () {
    $organizationA = verifiedProviderOrg();
    $organizationB = verifiedProviderOrg();
    $ownerA = orgOwner($organizationA);
    $ownerB = orgOwner($organizationB);
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id, 'verified_at' => now()]);

    foreach ([[$organizationA, $ownerA], [$organizationB, $ownerB]] as [$organization, $owner]) {
        $inviteRequest = OrganizationCounsellorRequestService::new()->inviteCounsellor(
            OrganizationCounsellorRequestDTO::new()->fromArray([
                'user' => $owner,
                'organization' => $organization,
                'counsellor' => $counsellor,
            ])
        );

        RequestService::new()->respondToRequest(
            RequestResponseDTO::new()->fromArray([
                'user' => $counsellorUser,
                'request' => $inviteRequest,
                'response' => 'accepted',
            ])
        );
    }

    expect($counsellor->organizationCounsellors()->count())->toBe(2);
});

test('an affiliated counsellor remains independently bookable', function () {
    $organization = verifiedProviderOrg();
    $owner = orgOwner($organization);
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id, 'verified_at' => now()]);

    $inviteRequest = OrganizationCounsellorRequestService::new()->inviteCounsellor(
        OrganizationCounsellorRequestDTO::new()->fromArray([
            'user' => $owner,
            'organization' => $organization,
            'counsellor' => $counsellor,
        ])
    );

    RequestService::new()->respondToRequest(
        RequestResponseDTO::new()->fromArray([
            'user' => $counsellorUser,
            'request' => $inviteRequest,
            'response' => 'accepted',
        ])
    );

    $independentTherapy = Therapy::factory()->create([
        'addedby_type' => Counsellor::class,
        'addedby_id' => $counsellor->id,
        'counsellor_id' => $counsellor->id,
    ]);

    expect($independentTherapy->exists)->toBeTrue();
    expect($counsellor->therapies()->count())->toBe(1);
});
