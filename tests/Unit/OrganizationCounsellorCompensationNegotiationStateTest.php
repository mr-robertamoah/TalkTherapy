<?php

use App\DTOs\OrganizationCounsellorCompensationDTO;
use App\DTOs\RequestResponseDTO;
use App\Enums\OrganizationAdminRoleEnum;
use App\Enums\OrganizationCounsellorCompensationTypeEnum;
use App\Enums\RequestStatusEnum;
use App\Exceptions\OrganizationException;
use App\Http\Resources\OrganizationCounsellorCompensationNegotiationStateResource;
use App\Models\Counsellor;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\User;
use App\Services\AppService;
use App\Services\OrganizationCounsellorCompensationService;
use App\Services\RequestService;

// SCRUM-150 (TT-6.4c, 5/5): a small, additive read exposing the current negotiation state for an
// affiliation, entirely separate from SCRUM-123's accepted-terms history (getCompensations()),
// which this must never leak into or be leaked into by.

function affiliationForNegotiationState(): array
{
    $organization = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id, 'verified_at' => now()]);

    $affiliation = OrganizationCounsellor::factory()->create([
        'organization_id' => $organization->id,
        'counsellor_id' => $counsellor->id,
    ]);

    return [$affiliation, $organization, $owner, $counsellor, $counsellorUser];
}

test('an affiliation with no negotiation history reports no active proposal', function () {
    [$affiliation, , $owner] = affiliationForNegotiationState();

    $state = OrganizationCounsellorCompensationService::new()->getNegotiationState(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
        ])
    );

    expect($state)->toBeNull();
});

test('a pending proposal from the org reports which direction it is pending in, with the proposed terms', function () {
    [$affiliation, $organization, $owner, $counsellor] = affiliationForNegotiationState();

    OrganizationCounsellorCompensationService::new()->proposeCompensationChange(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
            'amount' => 5000,
            'currency' => 'GHS',
        ])
    );

    $state = OrganizationCounsellorCompensationService::new()->getNegotiationState(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
        ])
    );

    expect($state->status)->toBe(RequestStatusEnum::pending->value);
    expect($state->from_type)->toBe(Organization::class);
    expect($state->to_type)->toBe(Counsellor::class);
    expect($state->round)->toBe(1);
    expect($state->data)->toMatchArray(['type' => 'FIXED', 'amount' => 5000, 'currency' => 'GHS']);
});

test('a pending counter-offer from the counsellor reports the reversed direction and the latest round', function () {
    [$affiliation, , $owner, , $counsellorUser] = affiliationForNegotiationState();

    $request = OrganizationCounsellorCompensationService::new()->proposeCompensationChange(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
        ])
    );

    OrganizationCounsellorCompensationService::new()->counterOffer(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $counsellorUser,
            'request' => $request,
            'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
            'amount' => 7500,
            'currency' => 'GHS',
        ])
    );

    $state = OrganizationCounsellorCompensationService::new()->getNegotiationState(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
        ])
    );

    expect($state->status)->toBe(RequestStatusEnum::pending->value);
    expect($state->from_type)->toBe(Counsellor::class);
    expect($state->to_type)->toBe(Organization::class);
    expect($state->round)->toBe(2);
    expect($state->data['amount'])->toBe(7500);
});

test('a manually rejected negotiation is distinguishable from an expired one', function () {
    [$affiliation, , $owner, , $counsellorUser] = affiliationForNegotiationState();

    $rejectedRequest = OrganizationCounsellorCompensationService::new()->proposeCompensationChange(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
        ])
    );
    RequestService::new()->respondToRequest(
        RequestResponseDTO::new()->fromArray([
            'user' => $counsellorUser,
            'request' => $rejectedRequest,
            'response' => 'rejected',
        ])
    );

    $state = OrganizationCounsellorCompensationService::new()->getNegotiationState(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
        ])
    );

    expect($state->status)->toBe(RequestStatusEnum::rejected->value);
    expect($state->data)->not->toHaveKey('resolvedBy');
});

test('an expired negotiation is reported distinguishably from a manual reject', function () {
    [$affiliation, , $owner] = affiliationForNegotiationState();

    $expiredRequest = OrganizationCounsellorCompensationService::new()->proposeCompensationChange(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
        ])
    );
    $expiredRequest->update(['expires_at' => now()->subDay()]);
    AppService::new()->expireStaleCompensationRequests();

    $state = OrganizationCounsellorCompensationService::new()->getNegotiationState(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
        ])
    );

    expect($state->status)->toBe(RequestStatusEnum::rejected->value);
    expect($state->data['resolvedBy'])->toBe('expiry');
});

test('an accepted negotiation is reported as resolved, with a fresh proposal always startable afterwards', function () {
    [$affiliation, , $owner, , $counsellorUser] = affiliationForNegotiationState();

    $request = OrganizationCounsellorCompensationService::new()->proposeCompensationChange(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
        ])
    );
    RequestService::new()->respondToRequest(
        RequestResponseDTO::new()->fromArray([
            'user' => $counsellorUser,
            'request' => $request,
            'response' => 'accepted',
        ])
    );

    $state = OrganizationCounsellorCompensationService::new()->getNegotiationState(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
        ])
    );

    expect($state->status)->toBe(RequestStatusEnum::accepted->value);

    // Nothing pending -- a fresh proposal is always allowed after a resolved negotiation.
    expect(fn () => OrganizationCounsellorCompensationService::new()->proposeCompensationChange(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
        ])
    ))->not->toThrow(OrganizationException::class);
});

test('the affiliated counsellor can also view the negotiation state', function () {
    [$affiliation, , $owner, , $counsellorUser] = affiliationForNegotiationState();

    OrganizationCounsellorCompensationService::new()->proposeCompensationChange(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
        ])
    );

    $state = OrganizationCounsellorCompensationService::new()->getNegotiationState(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $counsellorUser,
            'organizationCounsellor' => $affiliation,
        ])
    );

    expect($state->status)->toBe(RequestStatusEnum::pending->value);
});

test('a user with no relationship to the affiliation cannot view its negotiation state', function () {
    [$affiliation] = affiliationForNegotiationState();
    $outsider = User::factory()->create();

    expect(fn () => OrganizationCounsellorCompensationService::new()->getNegotiationState(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $outsider,
            'organizationCounsellor' => $affiliation,
        ])
    ))->toThrow(OrganizationException::class);
});

test('reading the negotiation state for a non-existent affiliation returns a clean error, not a crash', function () {
    $owner = User::factory()->create();

    expect(fn () => OrganizationCounsellorCompensationService::new()->getNegotiationState(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => null,
        ])
    ))->toThrow(OrganizationException::class);
});

// AC4: SCRUM-123's existing accepted-terms history endpoint is unmodified -- a pending or
// rejected/expired negotiation must never appear there.
test('a pending or resolved negotiation never leaks into the accepted-terms compensation history', function () {
    [$affiliation, , $owner, , $counsellorUser] = affiliationForNegotiationState();

    OrganizationCounsellorCompensationService::new()->proposeCompensationChange(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
            'amount' => 5000,
            'currency' => 'GHS',
        ])
    );

    $compensations = OrganizationCounsellorCompensationService::new()->getCompensations(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
        ])
    );

    expect($compensations)->toHaveCount(0);
});

test('the resource renders "none" for an affiliation with no negotiation history', function () {
    $resource = (new OrganizationCounsellorCompensationNegotiationStateResource(null))->toArray(request());

    expect($resource)->toBe(['state' => 'none']);
});

test('the resource renders a pending state with direction and proposed terms', function () {
    [$affiliation, $organization, $owner] = affiliationForNegotiationState();

    $request = OrganizationCounsellorCompensationService::new()->proposeCompensationChange(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
            'amount' => 5000,
            'currency' => 'GHS',
        ])
    );

    $resource = json_decode(json_encode(new OrganizationCounsellorCompensationNegotiationStateResource($request)), true);

    expect($resource['state'])->toBe('pending');
    expect($resource['from']['isOrganization'])->toBeTrue();
    expect($resource['to']['isCounsellor'])->toBeTrue();
    expect($resource['proposedTerms'])->toMatchArray(['type' => 'FIXED', 'amount' => 5000]);
    expect($resource)->toHaveKey('expiresAt');
    expect($resource)->not->toHaveKey('resolvedBy');
});

test('the resource distinguishes an expired resolution from a manual reject', function () {
    [$affiliation, , $owner] = affiliationForNegotiationState();

    $request = OrganizationCounsellorCompensationService::new()->proposeCompensationChange(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
        ])
    );
    $request->update(['expires_at' => now()->subDay()]);
    AppService::new()->expireStaleCompensationRequests();

    $resource = (new OrganizationCounsellorCompensationNegotiationStateResource($request->fresh()))->toArray(request());

    expect($resource['state'])->toBe('resolved');
    expect($resource['status'])->toBe(RequestStatusEnum::rejected->value);
    expect($resource['resolvedBy'])->toBe('expiry');
    expect($resource)->not->toHaveKey('expiresAt');
});
