<?php

use App\Actions\Organization\CreateOrganizationCounsellorCompensationAction;
use App\DTOs\OrganizationCounsellorCompensationDTO;
use App\Enums\OrganizationAdminRoleEnum;
use App\Enums\OrganizationCounsellorCompensationBasisEnum;
use App\Enums\OrganizationCounsellorCompensationTypeEnum;
use App\Enums\OrganizationCounsellorStatusEnum;
use App\Models\Counsellor;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\User;

// SCRUM-146 (TT-6.4c): CreateOrganizationCounsellorCompensationAction itself is completely
// unchanged by this ticket -- only its caller changes, from
// OrganizationCounsellorCompensationService::setCompensation() (an org admin's direct, unilateral
// write, now removed) to the accept step of the new negotiation flow (SCRUM-147). This file moves
// the SCRUM-122 mechanics coverage that used to go through the removed service method to call
// this action directly, so the underlying row-creation/activation/versioning behavior this
// feature is built on top of stays proven, unaffected by the flow change above it.

function pendingAffiliationForCompensationAction(): array
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

test('creating fixed compensation on a pending affiliation creates a row and activates it', function () {
    [$affiliation, , $owner] = pendingAffiliationForCompensationAction();

    $compensation = CreateOrganizationCounsellorCompensationAction::new()->execute(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
            'amount' => 5000,
            'currency' => 'GHS',
        ])
    );

    expect($compensation->type)->toBe(OrganizationCounsellorCompensationTypeEnum::fixed->value);
    expect($compensation->amount)->toBe(5000);
    expect($compensation->currency)->toBe('GHS');
    expect($affiliation->refresh()->status)->toBe(OrganizationCounsellorStatusEnum::active->value);
});

test('creating percentage compensation requires and records a basis', function () {
    [$affiliation, , $owner] = pendingAffiliationForCompensationAction();

    $compensation = CreateOrganizationCounsellorCompensationAction::new()->execute(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::percentage->value,
            'percentage' => 30,
            'basis' => OrganizationCounsellorCompensationBasisEnum::counsellorRate->value,
        ])
    );

    expect($compensation->percentage)->toBe(30);
    expect($compensation->basis)->toBe(OrganizationCounsellorCompensationBasisEnum::counsellorRate->value);
    expect($affiliation->refresh()->status)->toBe(OrganizationCounsellorStatusEnum::active->value);
});

test('creating free compensation activates the affiliation with no amount or percentage', function () {
    [$affiliation, , $owner] = pendingAffiliationForCompensationAction();

    $compensation = CreateOrganizationCounsellorCompensationAction::new()->execute(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
        ])
    );

    expect($compensation->amount)->toBeNull();
    expect($compensation->percentage)->toBeNull();
    expect($affiliation->refresh()->status)->toBe(OrganizationCounsellorStatusEnum::active->value);
});

test('a second call inserts a new row and preserves the old one, unmutated', function () {
    [$affiliation, , $owner] = pendingAffiliationForCompensationAction();

    $original = CreateOrganizationCounsellorCompensationAction::new()->execute(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
            'amount' => 5000,
            'currency' => 'GHS',
        ])
    );

    $renegotiated = CreateOrganizationCounsellorCompensationAction::new()->execute(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
            'amount' => 7500,
            'currency' => 'GHS',
        ])
    );

    expect($affiliation->compensations()->count())->toBe(2);
    expect($original->refresh()->amount)->toBe(5000);
    expect($renegotiated->amount)->toBe(7500);
    expect($affiliation->currentCompensation()->id)->toBe($renegotiated->id);
});

test('creating compensation on an already-active affiliation does not change its status', function () {
    [$affiliation, , $owner] = pendingAffiliationForCompensationAction();
    $affiliation->activate();

    CreateOrganizationCounsellorCompensationAction::new()->execute(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
        ])
    );

    expect($affiliation->refresh()->status)->toBe(OrganizationCounsellorStatusEnum::active->value);
});

test('creating compensation records who set it', function () {
    [$affiliation, , $owner] = pendingAffiliationForCompensationAction();

    $compensation = CreateOrganizationCounsellorCompensationAction::new()->execute(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
        ])
    );

    expect($compensation->set_by_id)->toBe($owner->id);
    expect($compensation->setBy->id)->toBe($owner->id);
});
