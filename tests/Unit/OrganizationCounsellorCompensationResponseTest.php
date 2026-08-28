<?php

use App\Actions\Organization\CreateOrganizationCounsellorCompensationAction;
use App\DTOs\OrganizationCounsellorCompensationDTO;
use App\DTOs\RequestResponseDTO;
use App\Enums\OrganizationAdminRoleEnum;
use App\Enums\OrganizationCounsellorCompensationTypeEnum;
use App\Enums\OrganizationCounsellorStatusEnum;
use App\Enums\RequestStatusEnum;
use App\Exceptions\CannotRespondToRequestException;
use App\Exceptions\OrganizationException;
use App\Http\Resources\OrganizationRequestResource;
use App\Models\Counsellor;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\Request;
use App\Models\User;
use App\Notifications\OrganizationCounsellorCompensationChangeAcceptedNotification;
use App\Notifications\OrganizationCounsellorCompensationChangeRejectedNotification;
use App\Services\OrganizationCounsellorCompensationService;
use App\Services\RequestService;
use Illuminate\Support\Facades\Notification;

// SCRUM-147 (TT-6.4c, 2/5): accept/reject a pending compensation-change proposal. Responding is
// gated entirely by the existing, unchanged EnsureUserCanRespondToRequestAction (its `to`-party
// checks already cover this type's `to` being a Counsellor) -- confirmed unnecessary to add the
// bespoke authorization action the ticket originally called for; see decision-log.md.

function pendingCompensationProposal(array $terms = []): array
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

    $request = OrganizationCounsellorCompensationService::new()->proposeCompensationChange(
        OrganizationCounsellorCompensationDTO::new()->fromArray(array_merge([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
            'amount' => 5000,
            'currency' => 'GHS',
        ], $terms))
    );

    return [$request, $affiliation, $organization, $owner, $counsellor, $counsellorUser];
}

test('the counsellor can accept a pending compensation proposal, creating the compensation row and activating the affiliation', function () {
    Notification::fake();
    [$request, $affiliation, , $owner, $counsellor, $counsellorUser] = pendingCompensationProposal();

    $resource = RequestService::new()->respondToRequest(
        RequestResponseDTO::new()->fromArray([
            'user' => $counsellorUser,
            'request' => $request,
            'response' => 'accepted',
        ])
    );

    expect($resource)->toBeInstanceOf(OrganizationRequestResource::class);
    expect($request->refresh()->status)->toBe(RequestStatusEnum::accepted->value);

    expect($affiliation->compensations()->count())->toBe(1);
    $compensation = $affiliation->currentCompensation();
    expect($compensation->amount)->toBe(5000);
    expect($compensation->currency)->toBe('GHS');
    // Attributed to the original proposer (the org admin), not the counsellor who clicked accept.
    expect($compensation->set_by_id)->toBe($owner->id);

    expect($affiliation->refresh()->status)->toBe(OrganizationCounsellorStatusEnum::active->value);

    Notification::assertSentTo($owner, OrganizationCounsellorCompensationChangeAcceptedNotification::class);
});

test('accepting a proposal on an already-active affiliation adds a new row without reactivating anything', function () {
    [$request, $affiliation, , , , $counsellorUser] = pendingCompensationProposal();
    $affiliation->activate();

    RequestService::new()->respondToRequest(
        RequestResponseDTO::new()->fromArray([
            'user' => $counsellorUser,
            'request' => $request,
            'response' => 'accepted',
        ])
    );

    expect($affiliation->compensations()->count())->toBe(1);
    expect($affiliation->refresh()->status)->toBe(OrganizationCounsellorStatusEnum::active->value);
});

test('the counsellor can reject a pending compensation proposal without creating a compensation row or changing the affiliation', function () {
    Notification::fake();
    [$request, $affiliation, , $owner, , $counsellorUser] = pendingCompensationProposal();

    $resource = RequestService::new()->respondToRequest(
        RequestResponseDTO::new()->fromArray([
            'user' => $counsellorUser,
            'request' => $request,
            'response' => 'rejected',
        ])
    );

    expect($resource)->toBeInstanceOf(OrganizationRequestResource::class);
    expect($request->refresh()->status)->toBe(RequestStatusEnum::rejected->value);
    expect($affiliation->compensations()->count())->toBe(0);
    expect($affiliation->refresh()->status)->toBe(OrganizationCounsellorStatusEnum::pending->value);

    Notification::assertSentTo($owner, OrganizationCounsellorCompensationChangeRejectedNotification::class);
});

// AC4: fairness-critical -- reject must never cascade into pausing/ending the affiliation, even
// when there's already an active affiliation with existing accepted terms behind it.
test('rejecting a renegotiation proposal never changes the affiliation status or its existing terms', function () {
    [$request, $affiliation, , , , $counsellorUser] = pendingCompensationProposal();
    $affiliation->activate();

    $existing = CreateOrganizationCounsellorCompensationAction::new()->execute(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $affiliation->organization->admins()->first(),
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
            'amount' => 9999,
            'currency' => 'GHS',
        ])
    );

    RequestService::new()->respondToRequest(
        RequestResponseDTO::new()->fromArray([
            'user' => $counsellorUser,
            'request' => $request,
            'response' => 'rejected',
        ])
    );

    expect($affiliation->refresh()->status)->toBe(OrganizationCounsellorStatusEnum::active->value);
    expect($affiliation->currentCompensation()->id)->toBe($existing->id);
    expect($affiliation->currentCompensation()->amount)->toBe(9999);
});

test('a user with no relationship to the affiliation cannot respond to its compensation proposal', function () {
    [$request] = pendingCompensationProposal();
    $outsider = User::factory()->create();

    expect(fn () => RequestService::new()->respondToRequest(
        RequestResponseDTO::new()->fromArray([
            'user' => $outsider,
            'request' => $request,
            'response' => 'accepted',
        ])
    ))->toThrow(CannotRespondToRequestException::class);

    expect($request->refresh()->status)->toBe(RequestStatusEnum::pending->value);
});

test('the proposing org admin cannot respond to their own proposal', function () {
    [$request, , , $owner] = pendingCompensationProposal();

    expect(fn () => RequestService::new()->respondToRequest(
        RequestResponseDTO::new()->fromArray([
            'user' => $owner,
            'request' => $request,
            'response' => 'accepted',
        ])
    ))->toThrow(CannotRespondToRequestException::class);
});

test('responding to an already-resolved compensation request is a no-op, not a second write', function () {
    [$request, $affiliation, , , , $counsellorUser] = pendingCompensationProposal();

    RequestService::new()->respondToRequest(
        RequestResponseDTO::new()->fromArray([
            'user' => $counsellorUser,
            'request' => $request,
            'response' => 'accepted',
        ])
    );

    RequestService::new()->respondToRequest(
        RequestResponseDTO::new()->fromArray([
            'user' => $counsellorUser,
            'request' => $request,
            'response' => 'rejected',
        ])
    );

    expect($request->refresh()->status)->toBe(RequestStatusEnum::accepted->value);
    expect($affiliation->compensations()->count())->toBe(1);
});

// AC5: rendering a compensation-change request via the real dispatch-aware resource machinery
// resolves correctly -- OrganizationRequestResource's for->organization fix already shipped with
// SCRUM-146, this proves it holds through the full respond pipeline too.
test('a responded-to compensation request renders through OrganizationRequestResource without error', function () {
    [$request, , $organization, , , $counsellorUser] = pendingCompensationProposal();

    $resource = RequestService::new()->respondToRequest(
        RequestResponseDTO::new()->fromArray([
            'user' => $counsellorUser,
            'request' => $request,
            'response' => 'accepted',
        ])
    )->toArray(request());

    expect($resource['organization']['id'])->toBe($organization->id);
    expect($resource['status'])->toBe(RequestStatusEnum::accepted->value);
});

// Review finding (reviewer + security-engineer, PR #85): accepting silently attributed the
// compensation row to a null set_by_id (or crashed with an unrelated PHP error) once the
// proposing admin's account was gone -- must fail loudly and cleanly instead, without blocking
// a reject on the same request.
test('accepting a proposal whose original proposer no longer exists is rejected with a clean error', function () {
    [$request, $affiliation, , $owner, , $counsellorUser] = pendingCompensationProposal();
    $owner->delete();

    expect(fn () => RequestService::new()->respondToRequest(
        RequestResponseDTO::new()->fromArray([
            'user' => $counsellorUser,
            'request' => $request,
            'response' => 'accepted',
        ])
    ))->toThrow(OrganizationException::class);

    expect($request->refresh()->status)->toBe(RequestStatusEnum::pending->value);
    expect($affiliation->compensations()->count())->toBe(0);
});

test('rejecting a proposal whose original proposer no longer exists still succeeds', function () {
    [$request, , , $owner, , $counsellorUser] = pendingCompensationProposal();
    $owner->delete();

    $resource = RequestService::new()->respondToRequest(
        RequestResponseDTO::new()->fromArray([
            'user' => $counsellorUser,
            'request' => $request,
            'response' => 'rejected',
        ])
    );

    expect($resource)->toBeInstanceOf(OrganizationRequestResource::class);
    expect($request->refresh()->status)->toBe(RequestStatusEnum::rejected->value);
});

// Review finding (reviewer, PR #85): eligibility (organization verified/provider, counsellor
// verified) is only checked at propose time -- the affiliation itself could have ended by the
// time the counsellor responds, mirroring RespondToOrganizationCounsellorRequestAction's own
// re-check of the organization's eligibility at accept time.
test('accepting a proposal for an affiliation that has since ended is rejected with a clean error', function () {
    [$request, $affiliation, , , , $counsellorUser] = pendingCompensationProposal();
    $affiliation->update(['status' => OrganizationCounsellorStatusEnum::ended->value]);

    expect(fn () => RequestService::new()->respondToRequest(
        RequestResponseDTO::new()->fromArray([
            'user' => $counsellorUser,
            'request' => $request,
            'response' => 'accepted',
        ])
    ))->toThrow(OrganizationException::class);

    expect($request->refresh()->status)->toBe(RequestStatusEnum::pending->value);
    expect($affiliation->compensations()->count())->toBe(0);
});

test('rejecting a proposal for an affiliation that has since ended still succeeds', function () {
    [$request, $affiliation, , , , $counsellorUser] = pendingCompensationProposal();
    $affiliation->update(['status' => OrganizationCounsellorStatusEnum::ended->value]);

    RequestService::new()->respondToRequest(
        RequestResponseDTO::new()->fromArray([
            'user' => $counsellorUser,
            'request' => $request,
            'response' => 'rejected',
        ])
    );

    expect($request->refresh()->status)->toBe(RequestStatusEnum::rejected->value);
    expect($affiliation->refresh()->status)->toBe(OrganizationCounsellorStatusEnum::ended->value);
});
