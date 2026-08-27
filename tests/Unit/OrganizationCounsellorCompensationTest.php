<?php

use App\DTOs\OrganizationCounsellorCompensationDTO;
use App\Enums\OrganizationAdminRoleEnum;
use App\Enums\OrganizationCounsellorCompensationBasisEnum;
use App\Enums\OrganizationCounsellorCompensationTypeEnum;
use App\Enums\OrganizationCounsellorStatusEnum;
use App\Exceptions\OrganizationException;
use App\Models\Counsellor;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\User;
use App\Services\OrganizationCounsellorCompensationService;

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

test('setting fixed compensation on a pending affiliation creates a row and activates it', function () {
    [$affiliation, , $owner] = pendingAffiliation();

    $compensation = OrganizationCounsellorCompensationService::new()->setCompensation(
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

test('setting percentage compensation requires and records a basis', function () {
    [$affiliation, , $owner] = pendingAffiliation();

    $compensation = OrganizationCounsellorCompensationService::new()->setCompensation(
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

test('setting free compensation activates the affiliation with no amount or percentage', function () {
    [$affiliation, , $owner] = pendingAffiliation();

    $compensation = OrganizationCounsellorCompensationService::new()->setCompensation(
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

test('renegotiating terms inserts a new row and preserves the old one, unmutated', function () {
    [$affiliation, , $owner] = pendingAffiliation();

    $original = OrganizationCounsellorCompensationService::new()->setCompensation(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
            'amount' => 5000,
            'currency' => 'GHS',
        ])
    );

    $renegotiated = OrganizationCounsellorCompensationService::new()->setCompensation(
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

test('a user who does not administer the organization cannot set compensation terms', function () {
    [$affiliation] = pendingAffiliation();
    $outsider = User::factory()->create();

    expect(fn () => OrganizationCounsellorCompensationService::new()->setCompensation(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $outsider,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
        ])
    ))->toThrow(OrganizationException::class);
});

test('a fixed compensation without an amount and currency is rejected', function () {
    [$affiliation, , $owner] = pendingAffiliation();

    expect(fn () => OrganizationCounsellorCompensationService::new()->setCompensation(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
        ])
    ))->toThrow(OrganizationException::class);
});

test('a percentage compensation without a basis is rejected', function () {
    [$affiliation, , $owner] = pendingAffiliation();

    expect(fn () => OrganizationCounsellorCompensationService::new()->setCompensation(
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

    expect(fn () => OrganizationCounsellorCompensationService::new()->setCompensation(
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

    expect(fn () => OrganizationCounsellorCompensationService::new()->setCompensation(
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

    expect(fn () => OrganizationCounsellorCompensationService::new()->setCompensation(
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

test('setting compensation for a non-existent affiliation returns a clean error, not a crash', function () {
    $owner = User::factory()->create();

    expect(fn () => OrganizationCounsellorCompensationService::new()->setCompensation(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => null,
            'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
        ])
    ))->toThrow(OrganizationException::class);
});

test('setting compensation on an already-active affiliation does not change its status', function () {
    [$affiliation, , $owner] = pendingAffiliation();
    $affiliation->activate();

    OrganizationCounsellorCompensationService::new()->setCompensation(
        OrganizationCounsellorCompensationDTO::new()->fromArray([
            'user' => $owner,
            'organizationCounsellor' => $affiliation,
            'type' => OrganizationCounsellorCompensationTypeEnum::free->value,
        ])
    );

    expect($affiliation->refresh()->status)->toBe(OrganizationCounsellorStatusEnum::active->value);
});
