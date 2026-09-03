<?php

use App\Actions\Organization\CreateOrganizationCounsellorCompensationAction;
use App\DTOs\OrganizationCounsellorCompensationDTO;
use App\DTOs\RequestResponseDTO;
use App\Enums\OrganizationAdminRoleEnum;
use App\Enums\OrganizationCounsellorCompensationBasisEnum;
use App\Enums\OrganizationCounsellorCompensationTypeEnum;
use App\Enums\OrganizationCounsellorStatusEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Exceptions\OrganizationException;
use App\Models\Counsellor;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\Request;
use App\Models\User;
use App\Notifications\OrganizationCounsellorCompensationChangeProposedNotification;
use App\Services\OrganizationCounsellorCompensationService;
use App\Services\RequestService;
use Illuminate\Support\Facades\Notification;

// SCRUM-146 (TT-6.4c): OrganizationCounsellorCompensationService::setCompensation() -- an org
// admin's direct, unilateral, immediately-effective write -- has been removed. Its business-rule
// guarding (authorization, field-consistency validation) now guards proposeCompensationChange()
// instead, which creates a pending Request rather than writing to
// organization_counsellor_compensations directly. The underlying row-creation/activation/
// versioning mechanics this file used to prove via setCompensation() are now covered directly
// against CreateOrganizationCounsellorCompensationAction in
// tests/Unit/CreateOrganizationCounsellorCompensationActionTest.php, since that action is
// unchanged and is what SCRUM-147's accept step will call.

function pendingAffiliation(): array
{
    $organization = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory(), 'verified_at' => now()]);

    $affiliation = OrganizationCounsellor::factory()->create([
        'organization_id' => $organization->id,
        'counsellor_id' => $counsellor->id,
    ]);

    return [$affiliation, $organization, $owner, $counsellor];
}

test('proposing fixed compensation creates a pending request, not a compensation row', function () {
    Notification::fake();
    [$affiliation, $organization, $owner, $counsellor] = pendingAffiliation();

    $request = OrganizationCounsellorCompensationService::new()->proposeCompensationChange(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
            'amount' => 5000,
            'currency' => 'GHS',
        ])
    );

    expect($request)->toBeInstanceOf(Request::class);
    expect($request->type)->toBe(RequestTypeEnum::organizationCounsellorCompensationChange->value);
    expect($request->status)->toBe(RequestStatusEnum::pending->value);
    expect($request->data)->toMatchArray(['type' => 'FIXED', 'amount' => 5000, 'currency' => 'GHS']);
    expect($request->from_type)->toBe(Organization::class);
    expect($request->from_id)->toBe($organization->id);
    expect($request->to_type)->toBe(Counsellor::class);
    expect($request->to_id)->toBe($counsellor->id);
    expect($request->for_type)->toBe(OrganizationCounsellor::class);
    expect($request->for_id)->toBe($affiliation->id);
    expect($request->round)->toBe(1);

    expect($affiliation->compensations()->count())->toBe(0);
    expect($affiliation->refresh()->status)->toBe(OrganizationCounsellorStatusEnum::pending->value);

    Notification::assertSentTo($counsellor, OrganizationCounsellorCompensationChangeProposedNotification::class);
});

test('proposing percentage compensation requires and records a basis in the request data', function () {
    [$affiliation, , $owner] = pendingAffiliation();

    $request = OrganizationCounsellorCompensationService::new()->proposeCompensationChange(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::percentage->value,
            'percentage' => 30,
            'basis' => OrganizationCounsellorCompensationBasisEnum::counsellorRate->value,
        ])
    );

    expect($request->data)->toMatchArray([
        'type' => 'PERCENTAGE',
        'percentage' => 30,
        'basis' => OrganizationCounsellorCompensationBasisEnum::counsellorRate->value,
    ]);
});

test('proposing uses the configured default expiry when none is given', function () {
    [$affiliation, , $owner] = pendingAffiliation();

    $request = OrganizationCounsellorCompensationService::new()->proposeCompensationChange(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
        ])
    );

    $expectedDays = config('organization.compensation_negotiation_default_expiry_days');
    expect($request->expires_at->diffInDays(now(), true))->toBeLessThanOrEqual($expectedDays);
    expect($request->expires_at->isToday() || $request->expires_at->isFuture())->toBeTrue();
    expect(now()->addDays($expectedDays)->diffInMinutes($request->expires_at, true))->toBeLessThan(1);
});

test('proposing with a custom expiryDays overrides the configured default', function () {
    [$affiliation, , $owner] = pendingAffiliation();

    $request = OrganizationCounsellorCompensationService::new()->proposeCompensationChange(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
            'expiryDays' => 14,
        ])
    );

    expect(now()->addDays(14)->diffInMinutes($request->expires_at, true))->toBeLessThan(1);
});

test('an expiryDays override outside 1-30 is rejected', function () {
    [$affiliation, , $owner] = pendingAffiliation();

    expect(fn () => OrganizationCounsellorCompensationService::new()->proposeCompensationChange(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
            'expiryDays' => 31,
        ])
    ))->toThrow(OrganizationException::class);
});

test('proposing while one is already pending for this affiliation is rejected', function () {
    [$affiliation, , $owner] = pendingAffiliation();

    OrganizationCounsellorCompensationService::new()->proposeCompensationChange(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
        ])
    );

    expect(fn () => OrganizationCounsellorCompensationService::new()->proposeCompensationChange(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
            'amount' => 5000,
            'currency' => 'GHS',
        ])
    ))->toThrow(OrganizationException::class);
});

test('a user who does not administer the organization cannot propose compensation terms', function () {
    [$affiliation] = pendingAffiliation();
    $outsider = User::factory()->create();

    expect(fn () => OrganizationCounsellorCompensationService::new()->proposeCompensationChange(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $outsider,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
        ])
    ))->toThrow(OrganizationException::class);
});

test('a fixed compensation without an amount and currency is rejected', function () {
    [$affiliation, , $owner] = pendingAffiliation();

    expect(fn () => OrganizationCounsellorCompensationService::new()->proposeCompensationChange(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
        ])
    ))->toThrow(OrganizationException::class);
});

test('a percentage compensation without a basis is rejected', function () {
    [$affiliation, , $owner] = pendingAffiliation();

    expect(fn () => OrganizationCounsellorCompensationService::new()->proposeCompensationChange(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::percentage->value,
            'percentage' => 20,
        ])
    ))->toThrow(OrganizationException::class);
});

test('a free compensation carrying a leftover amount is rejected', function () {
    [$affiliation, , $owner] = pendingAffiliation();

    expect(fn () => OrganizationCounsellorCompensationService::new()->proposeCompensationChange(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
            'amount' => 100,
        ])
    ))->toThrow(OrganizationException::class);
});

test('a fixed compensation carrying a leftover percentage or basis is rejected', function () {
    [$affiliation, , $owner] = pendingAffiliation();

    expect(fn () => OrganizationCounsellorCompensationService::new()->proposeCompensationChange(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
            'amount' => 5000,
            'currency' => 'GHS',
            'percentage' => 30,
            'basis' => OrganizationCounsellorCompensationBasisEnum::counsellorRate->value,
        ])
    ))->toThrow(OrganizationException::class);
});

test('a percentage compensation carrying a leftover amount or currency is rejected', function () {
    [$affiliation, , $owner] = pendingAffiliation();

    expect(fn () => OrganizationCounsellorCompensationService::new()->proposeCompensationChange(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::percentage->value,
            'percentage' => 30,
            'basis' => OrganizationCounsellorCompensationBasisEnum::counsellorRate->value,
            'amount' => 5000,
            'currency' => 'GHS',
        ])
    ))->toThrow(OrganizationException::class);
});

// TT-7.3b-b0/SCRUM-232: NEGOTIATED_RATE basis previously had nowhere to store the actual
// negotiated number -- these pin down the new field's wiring through the full propose->accept
// negotiation flow, not just the raw model column.

test('proposing a negotiated-rate percentage compensation requires the rate amount', function () {
    [$affiliation, , $owner] = pendingAffiliation();

    expect(fn () => OrganizationCounsellorCompensationService::new()->proposeCompensationChange(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::percentage->value,
            'percentage' => 70,
            'basis' => OrganizationCounsellorCompensationBasisEnum::negotiatedRate->value,
        ])
    ))->toThrow(OrganizationException::class);
});

test('a counsellor-rate-basis percentage compensation cannot carry a negotiated rate amount', function () {
    [$affiliation, , $owner] = pendingAffiliation();

    expect(fn () => OrganizationCounsellorCompensationService::new()->proposeCompensationChange(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::percentage->value,
            'percentage' => 70,
            'basis' => OrganizationCounsellorCompensationBasisEnum::counsellorRate->value,
            'negotiatedRateAmount' => 25000,
        ])
    ))->toThrow(OrganizationException::class);
});

test('a fixed compensation cannot carry a negotiated rate amount', function () {
    [$affiliation, , $owner] = pendingAffiliation();

    expect(fn () => OrganizationCounsellorCompensationService::new()->proposeCompensationChange(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
            'amount' => 5000,
            'currency' => 'GHS',
            'negotiatedRateAmount' => 25000,
        ])
    ))->toThrow(OrganizationException::class);
});

test('proposing a valid negotiated-rate percentage compensation records the rate amount in the request data', function () {
    [$affiliation, , $owner] = pendingAffiliation();

    $request = OrganizationCounsellorCompensationService::new()->proposeCompensationChange(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::percentage->value,
            'percentage' => 70,
            'basis' => OrganizationCounsellorCompensationBasisEnum::negotiatedRate->value,
            'negotiatedRateAmount' => 25000,
        ])
    );

    expect($request->data)->toMatchArray([
        'type' => 'PERCENTAGE',
        'percentage' => 70,
        'basis' => OrganizationCounsellorCompensationBasisEnum::negotiatedRate->value,
        'negotiatedRateAmount' => 25000,
    ]);
});

test('accepting a negotiated-rate proposal persists the rate amount on the compensation row', function () {
    [$affiliation, , $owner, $counsellor] = pendingAffiliation();

    $request = OrganizationCounsellorCompensationService::new()->proposeCompensationChange(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::percentage->value,
            'percentage' => 70,
            'basis' => OrganizationCounsellorCompensationBasisEnum::negotiatedRate->value,
            'negotiatedRateAmount' => 25000,
        ])
    );

    RequestService::new()->respondToRequest(
        RequestResponseDTO::new()->fromArray([
            'user' => $counsellor->user,
            'request' => $request,
            'response' => 'accepted',
        ])
    );

    $compensation = $affiliation->currentCompensation();
    expect($compensation->basis)->toBe(OrganizationCounsellorCompensationBasisEnum::negotiatedRate->value);
    expect($compensation->negotiated_rate_amount)->toBe(25000);
});

test('proposing compensation for a non-existent affiliation returns a clean error, not a crash', function () {
    $owner = User::factory()->create();

    expect(fn () => OrganizationCounsellorCompensationService::new()->proposeCompensationChange(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => null,
            'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
        ])
    ))->toThrow(OrganizationException::class);
});

test('proposing compensation on an already-active affiliation does not change its status or terms', function () {
    [$affiliation, , $owner] = pendingAffiliation();
    $affiliation->activate();

    OrganizationCounsellorCompensationService::new()->proposeCompensationChange(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
        ])
    );

    expect($affiliation->refresh()->status)->toBe(OrganizationCounsellorStatusEnum::active->value);
    expect($affiliation->currentCompensation())->toBeNull();
});

// SCRUM-146 AC5: organization_counsellor_compensations schema and currentCompensation()'s
// resolution logic must be completely unaffected -- a pending proposal must never surface there.

test('currentCompensation never returns a pending proposal\'s terms', function () {
    [$affiliation, , $owner] = pendingAffiliation();

    CreateOrganizationCounsellorCompensationAction::new()->execute(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
            'amount' => 5000,
            'currency' => 'GHS',
        ])
    );

    OrganizationCounsellorCompensationService::new()->proposeCompensationChange(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
            'amount' => 9999,
            'currency' => 'GHS',
        ])
    );

    expect($affiliation->currentCompensation()->amount)->toBe(5000);
});

// SCRUM-123: accountability trail + read path -- unaffected by this ticket. Fixture setup now
// goes through CreateOrganizationCounsellorCompensationAction directly (the accepted-terms path),
// not the removed setCompensation().

test('an organization admin can read the full compensation history for an affiliation', function () {
    [$affiliation, , $owner] = pendingAffiliation();

    CreateOrganizationCounsellorCompensationAction::new()->execute(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
            'amount' => 5000,
            'currency' => 'GHS',
        ])
    );
    CreateOrganizationCounsellorCompensationAction::new()->execute(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
            'amount' => 7500,
            'currency' => 'GHS',
        ])
    );

    $compensations = OrganizationCounsellorCompensationService::new()->getCompensations(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
        ])
    );

    expect($compensations)->toHaveCount(2);
    expect($compensations->first()->amount)->toBe(7500); // most recent first
});

test('the affiliated counsellor can read their own compensation history', function () {
    [$affiliation, , $owner, $counsellor] = pendingAffiliation();

    CreateOrganizationCounsellorCompensationAction::new()->execute(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
        ])
    );

    $compensations = OrganizationCounsellorCompensationService::new()->getCompensations(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $counsellor->user,
            'organizationCounsellor' => $affiliation,
        ])
    );

    expect($compensations)->toHaveCount(1);
});

test('a user with no relationship to the affiliation cannot read its compensation history', function () {
    [$affiliation, , $owner] = pendingAffiliation();
    $outsider = User::factory()->create();

    CreateOrganizationCounsellorCompensationAction::new()->execute(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
        ])
    );

    expect(fn () => OrganizationCounsellorCompensationService::new()->getCompensations(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $outsider,
            'organizationCounsellor' => $affiliation,
        ])
    ))->toThrow(OrganizationException::class);
});

test('an admin of a different organization cannot read this affiliation\'s compensation history', function () {
    [$affiliationA, , $ownerA] = pendingAffiliation();
    [, , $ownerB] = pendingAffiliation(); // a second, unrelated organization and admin

    CreateOrganizationCounsellorCompensationAction::new()->execute(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $ownerA,
            'organizationCounsellor' => $affiliationA,
            'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
        ])
    );

    expect(fn () => OrganizationCounsellorCompensationService::new()->getCompensations(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $ownerB,
            'organizationCounsellor' => $affiliationA,
        ])
    ))->toThrow(OrganizationException::class);
});

test('a counsellor affiliated with a different organization cannot read this affiliation\'s compensation history', function () {
    [$affiliationA, , $ownerA] = pendingAffiliation();
    [, , , $otherCounsellor] = pendingAffiliation(); // a different org, different counsellor

    CreateOrganizationCounsellorCompensationAction::new()->execute(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $ownerA,
            'organizationCounsellor' => $affiliationA,
            'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
        ])
    );

    expect(fn () => OrganizationCounsellorCompensationService::new()->getCompensations(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $otherCounsellor->user,
            'organizationCounsellor' => $affiliationA,
        ])
    ))->toThrow(OrganizationException::class);
});

// Review finding (security-engineer, PR #84): RequestService::getRequests() renders every hit
// through the generic RequestResource (not GetRequestResourceAction's per-type dispatch), which
// previously assumed any non-User from/to was a Counsellor and any unmatched for_type fell back
// to CounsellorMiniResource -- both wrong for this type's Organization from_type and
// OrganizationCounsellor for_type, throwing a BadMethodCallException. Covers the counsellor
// (`to`) side; the organization admin (`from`) side goes through the same getFrom()/getFor() code.
test('a counsellor with a pending compensation proposal can load their requests list without error', function () {
    [$affiliation, , $owner, $counsellor] = pendingAffiliation();

    OrganizationCounsellorCompensationService::new()->proposeCompensationChange(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
        ])
    );

    $resources = RequestService::new()->getRequests('', $counsellor->user);

    expect($resources->collection)->toHaveCount(1);
    expect($resources->collection->first()->resource->type)
        ->toBe(RequestTypeEnum::organizationCounsellorCompensationChange->value);
});

test('reading compensation history for a non-existent affiliation returns a clean error, not a crash', function () {
    $owner = User::factory()->create();

    expect(fn () => OrganizationCounsellorCompensationService::new()->getCompensations(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => null,
        ])
    ))->toThrow(OrganizationException::class);
});
