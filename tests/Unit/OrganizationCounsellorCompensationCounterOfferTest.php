<?php

use App\DTOs\OrganizationCounsellorCompensationDTO;
use App\DTOs\RequestResponseDTO;
use App\Enums\OrganizationAdminRoleEnum;
use App\Enums\OrganizationCounsellorCompensationBasisEnum;
use App\Enums\OrganizationCounsellorCompensationTypeEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Exceptions\CannotRespondToRequestException;
use App\Exceptions\OrganizationException;
use App\Exceptions\RequestNotFoundException;
use App\Models\Counsellor;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\Request;
use App\Models\User;
use App\Notifications\OrganizationCounsellorCompensationChangeProposedNotification;
use App\Services\OrganizationCounsellorCompensationService;
use App\Services\RequestService;
use Illuminate\Support\Facades\Notification;

// SCRUM-148 (TT-6.4c, 3/5): counter-offer for a pending compensation-change proposal. Only the
// current `to`-party may counter (reuses the same generic EnsureUserCanRespondToRequestAction as
// accept/reject -- confirmed in SCRUM-147 to already handle both Counsellor and
// Organization-typed respondents), up to a configured round cap.

function pendingCompensationNegotiation(array $terms = []): array
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

test('the counsellor can counter-offer a pending proposal with different terms', function () {
    Notification::fake();
    [$request, $affiliation, $organization, $owner, , $counsellorUser] = pendingCompensationNegotiation();

    $counterOffer = OrganizationCounsellorCompensationService::new()->counterOffer(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $counsellorUser,
            'request' => $request,
            'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
            'amount' => 7500,
            'currency' => 'GHS',
        ])
    );

    expect($request->refresh()->status)->toBe(RequestStatusEnum::rejected->value);

    expect($counterOffer->status)->toBe(RequestStatusEnum::pending->value);
    expect($counterOffer->type)->toBe(RequestTypeEnum::organizationCounsellorCompensationChange->value);
    expect($counterOffer->for_id)->toBe($affiliation->id);
    expect($counterOffer->from_type)->toBe(Counsellor::class);
    expect($counterOffer->to_type)->toBe(Organization::class);
    expect($counterOffer->to_id)->toBe($organization->id);
    expect($counterOffer->round)->toBe(2);
    expect($counterOffer->data)->toMatchArray(['type' => 'FIXED', 'amount' => 7500, 'currency' => 'GHS']);
    expect($counterOffer->data['proposedById'])->toBe($counsellorUser->id);

    Notification::assertSentTo($owner, OrganizationCounsellorCompensationChangeProposedNotification::class);
});

// TT-7.3b-b0/SCRUM-232: the negotiated-rate field is code-identical wiring to every other field
// in this action, but a counter-offer round is its own independent code path from a direct
// propose-then-accept -- worth its own explicit coverage rather than assuming it's covered.
test('negotiatedRateAmount survives a counter-offer round and persists on eventual acceptance', function () {
    [$request, $affiliation, , $owner, $counsellor, $counsellorUser] = pendingCompensationNegotiation();

    $counterOffer = OrganizationCounsellorCompensationService::new()->counterOffer(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $counsellorUser,
            'request' => $request,
            'type' => OrganizationCounsellorCompensationTypeEnum::percentage->value,
            'percentage' => 70,
            'basis' => OrganizationCounsellorCompensationBasisEnum::negotiatedRate->value,
            'negotiatedRateAmount' => 25000,
        ])
    );

    expect($counterOffer->data['negotiatedRateAmount'])->toBe(25000);

    RequestService::new()->respondToRequest(
        RequestResponseDTO::new()->fromArray([
            'user' => $owner,
            'request' => $counterOffer,
            'response' => 'accepted',
        ])
    );

    expect($affiliation->currentCompensation()->negotiated_rate_amount)->toBe(25000);
});

test('at no point does more than one pending request exist for the affiliation across a counter-offer', function () {
    [$request, $affiliation] = pendingCompensationNegotiation();

    OrganizationCounsellorCompensationService::new()->counterOffer(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $affiliation->counsellor->user,
            'request' => $request,
            'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
        ])
    );

    expect(
        Request::query()->whereFor($affiliation)->wherePending()->count()
    )->toBe(1);
});

test('a counter-offer can itself be countered again, and correctly attributes acceptance to whoever proposed the latest round', function () {
    Notification::fake();
    [$request, $affiliation, $organization, $owner, , $counsellorUser] = pendingCompensationNegotiation();

    $round2 = OrganizationCounsellorCompensationService::new()->counterOffer(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $counsellorUser,
            'request' => $request,
            'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
            'amount' => 7500,
            'currency' => 'GHS',
        ])
    );

    $round3 = OrganizationCounsellorCompensationService::new()->counterOffer(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'request' => $round2,
            'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
            'amount' => 6000,
            'currency' => 'GHS',
        ])
    );

    expect($round3->from_type)->toBe(Organization::class);
    expect($round3->to_type)->toBe(Counsellor::class);
    expect($round3->round)->toBe(3);

    RequestService::new()->respondToRequest(
        RequestResponseDTO::new()->fromArray([
            'user' => $counsellorUser,
            'request' => $round3,
            'response' => 'accepted',
        ])
    );

    $compensation = $affiliation->currentCompensation();
    expect($compensation->amount)->toBe(6000);
    // Attributed to whoever proposed round 3 (the org admin), not the counsellor who accepted it.
    expect($compensation->set_by_id)->toBe($owner->id);
});

test('countering past the configured round cap is rejected, leaving only accept/reject available', function () {
    config(['organization.compensation_negotiation_max_rounds' => 2]);
    [$request, $affiliation, , $owner, , $counsellorUser] = pendingCompensationNegotiation();

    $round2 = OrganizationCounsellorCompensationService::new()->counterOffer(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $counsellorUser,
            'request' => $request,
            'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
        ])
    );

    expect($round2->round)->toBe(2);

    expect(fn () => OrganizationCounsellorCompensationService::new()->counterOffer(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'request' => $round2,
            'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
        ])
    ))->toThrow(OrganizationException::class);

    // Still resolvable via accept/reject despite the round cap.
    expect($round2->refresh()->status)->toBe(RequestStatusEnum::pending->value);
    RequestService::new()->respondToRequest(
        RequestResponseDTO::new()->fromArray([
            'user' => $owner,
            'request' => $round2,
            'response' => 'rejected',
        ])
    );
    expect($round2->refresh()->status)->toBe(RequestStatusEnum::rejected->value);
    expect($affiliation->refresh()->status)->not->toBe('ACTIVE');
});

test('the round cap is read from config, not hardcoded', function () {
    config(['organization.compensation_negotiation_max_rounds' => 1]);
    [$request, , , , , $counsellorUser] = pendingCompensationNegotiation();

    expect(fn () => OrganizationCounsellorCompensationService::new()->counterOffer(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $counsellorUser,
            'request' => $request,
            'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
        ])
    ))->toThrow(OrganizationException::class);
});

test('someone other than the current to-party cannot counter-offer', function () {
    [$request] = pendingCompensationNegotiation();
    $outsider = User::factory()->create();

    expect(fn () => OrganizationCounsellorCompensationService::new()->counterOffer(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $outsider,
            'request' => $request,
            'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
        ])
    ))->toThrow(CannotRespondToRequestException::class);
});

test('the proposing org admin cannot counter-offer their own proposal', function () {
    [$request, , , $owner] = pendingCompensationNegotiation();

    expect(fn () => OrganizationCounsellorCompensationService::new()->counterOffer(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'request' => $request,
            'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
        ])
    ))->toThrow(CannotRespondToRequestException::class);
});

test('countering an already-resolved request is rejected with a clean error', function () {
    [$request, , , $owner, , $counsellorUser] = pendingCompensationNegotiation();

    RequestService::new()->respondToRequest(
        RequestResponseDTO::new()->fromArray([
            'user' => $counsellorUser,
            'request' => $request,
            'response' => 'rejected',
        ])
    );

    expect(fn () => OrganizationCounsellorCompensationService::new()->counterOffer(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $counsellorUser,
            'request' => $request,
            'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
        ])
    ))->toThrow(OrganizationException::class);
});

test('counter-offering with invalid terms is rejected the same way proposing is', function () {
    [$request, , , , , $counsellorUser] = pendingCompensationNegotiation();

    expect(fn () => OrganizationCounsellorCompensationService::new()->counterOffer(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $counsellorUser,
            'request' => $request,
            'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
        ])
    ))->toThrow(OrganizationException::class);

    expect($request->refresh()->status)->toBe(RequestStatusEnum::pending->value);
});

test('counter-offering a non-existent request returns a clean error, not a crash', function () {
    $user = User::factory()->create();

    expect(fn () => OrganizationCounsellorCompensationService::new()->counterOffer(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $user,
            'request' => null,
            'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
        ])
    ))->toThrow(RequestNotFoundException::class);
});

test('an org admin can counter-offer on the counsellor turn and any admin of the org can be notified, not just the one who last proposed', function () {
    Notification::fake();
    [$request, $affiliation, $organization, , , $counsellorUser] = pendingCompensationNegotiation();

    $secondAdmin = User::factory()->create();
    $organization->admins()->attach($secondAdmin->id, ['role' => OrganizationAdminRoleEnum::admin->value]);

    OrganizationCounsellorCompensationService::new()->counterOffer(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $counsellorUser,
            'request' => $request,
            'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
        ])
    );

    Notification::assertSentTo($secondAdmin, OrganizationCounsellorCompensationChangeProposedNotification::class);
});

// Security finding (PR #86): EnsureUserCanRespondToRequestAction only checks the `to`-party, not
// the request's type -- this endpoint (unlike accept/reject, which is only reached via
// RespondToRequestAction's own per-type dispatch) is hit directly by requestId with nothing else
// scoping it to compensation-change requests. Without an explicit type guard, a user legitimately
// `to` on some unrelated pending request could have it force-rejected and mutated as if it were a
// compensation negotiation.
test('counter-offering a pending request of a different type is rejected and leaves it untouched', function () {
    [, , , , , $counsellorUser] = pendingCompensationNegotiation();

    $unrelatedRequest = Request::factory()->create([
        'type' => RequestTypeEnum::guardianship->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => [],
        'to_type' => User::class,
        'to_id' => $counsellorUser->id,
    ]);

    expect(fn () => OrganizationCounsellorCompensationService::new()->counterOffer(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $counsellorUser,
            'request' => $unrelatedRequest,
            'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
        ])
    ))->toThrow(RequestNotFoundException::class);

    expect($unrelatedRequest->refresh()->status)->toBe(RequestStatusEnum::pending->value);
});

test('a counter-offer can override the default negotiation expiry', function () {
    [$request, , , , , $counsellorUser] = pendingCompensationNegotiation();

    $counterOffer = OrganizationCounsellorCompensationService::new()->counterOffer(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $counsellorUser,
            'request' => $request,
            'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
            'expiryDays' => 14,
        ])
    );

    expect(now()->addDays(14)->diffInMinutes($counterOffer->expires_at, true))->toBeLessThan(1);
});
