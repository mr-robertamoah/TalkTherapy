<?php

use App\Actions\Counsellor\EnsureCanDeleteCounsellorAction;
use App\DTOs\DeleteCounsellorDTO;
use App\Enums\AdministratorTypeEnum;
use App\Enums\CounsellorGroupTherapyRoleEnum;
use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Enums\OrganizationCounsellorSourceEnum;
use App\Enums\OrganizationCounsellorStatusEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\SessionStatusEnum;
use App\Enums\TherapyStatusEnum;
use App\Exceptions\CannotDeleteCounsellorException;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\Request;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;

// SCRUM-134: EnsureCanDeleteCounsellorAction previously only checked hasNoPendingSessions() --
// this covers the four new eligibility checks, and the authorize-first-then-validate ordering
// that keeps an unauthorized caller from being able to distinguish "not authorized" from
// "authorized but blocked by state X" for a counsellor they don't own.

function counsellorAndOwner(): array
{
    $owner = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $owner->id]);

    return [$counsellor, $owner];
}

function deleteCounsellorDTO(Counsellor $counsellor, User $user): DeleteCounsellorDTO
{
    return DeleteCounsellorDTO::new()->fromArray([
        'user' => $user,
        'counsellor' => $counsellor,
    ]);
}

test('the owner can delete an eligible counsellor account', function () {
    [$counsellor, $owner] = counsellorAndOwner();

    expect(fn () => EnsureCanDeleteCounsellorAction::new()->execute(deleteCounsellorDTO($counsellor, $owner)))
        ->not->toThrow(CannotDeleteCounsellorException::class);
});

test('a super admin can delete an eligible counsellor account', function () {
    [$counsellor] = counsellorAndOwner();
    $superAdmin = User::factory()->has(Administrator::factory())->create();

    expect(fn () => EnsureCanDeleteCounsellorAction::new()->execute(deleteCounsellorDTO($counsellor, $superAdmin)))
        ->not->toThrow(CannotDeleteCounsellorException::class);
});

test('a non-super admin cannot delete someone else\'s counsellor account', function () {
    [$counsellor] = counsellorAndOwner();
    $normalAdmin = User::factory()
        ->has(Administrator::factory()->state(['type' => AdministratorTypeEnum::normal->value]))
        ->create();

    expect(fn () => EnsureCanDeleteCounsellorAction::new()->execute(deleteCounsellorDTO($counsellor, $normalAdmin)))
        ->toThrow(CannotDeleteCounsellorException::class, 'You are either not authorized to delete this counsellor account or there are some sessions to finish.');
});

test('an unrelated user cannot delete someone else\'s counsellor account', function () {
    [$counsellor] = counsellorAndOwner();
    $stranger = User::factory()->create();

    expect(fn () => EnsureCanDeleteCounsellorAction::new()->execute(deleteCounsellorDTO($counsellor, $stranger)))
        ->toThrow(CannotDeleteCounsellorException::class, 'You are either not authorized to delete this counsellor account or there are some sessions to finish.');
});

test('an unauthorized caller gets the same generic message regardless of the counsellor\'s state', function () {
    [$counsellor] = counsellorAndOwner();
    $stranger = User::factory()->create();

    // Give the counsellor a state that would otherwise produce a specific message --
    // an unauthorized caller must never see it.
    Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => $counsellor->id,
        'status' => TherapyStatusEnum::in_session->value,
    ]);

    expect(fn () => EnsureCanDeleteCounsellorAction::new()->execute(deleteCounsellorDTO($counsellor, $stranger)))
        ->toThrow(CannotDeleteCounsellorException::class, 'You are either not authorized to delete this counsellor account or there are some sessions to finish.');
});

test('the owner is blocked by a pending session', function () {
    [$counsellor, $owner] = counsellorAndOwner();
    Session::factory()->create([
        'addedby_type' => Counsellor::class,
        'addedby_id' => $counsellor->id,
        'status' => SessionStatusEnum::pending->value,
    ]);

    expect(fn () => EnsureCanDeleteCounsellorAction::new()->execute(deleteCounsellorDTO($counsellor, $owner)))
        ->toThrow(CannotDeleteCounsellorException::class, 'You have sessions that need to be completed or cancelled before you can delete this counsellor account.');
});

test('the owner is blocked by a therapy currently in session', function () {
    [$counsellor, $owner] = counsellorAndOwner();
    Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => $counsellor->id,
        'status' => TherapyStatusEnum::in_session->value,
    ]);

    expect(fn () => EnsureCanDeleteCounsellorAction::new()->execute(deleteCounsellorDTO($counsellor, $owner)))
        ->toThrow(CannotDeleteCounsellorException::class, 'You have a therapy that is currently in session. Please end it before deleting this counsellor account.');
});

test('an ended therapy does not block deletion', function () {
    [$counsellor, $owner] = counsellorAndOwner();
    Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => $counsellor->id,
        'status' => TherapyStatusEnum::ended->value,
    ]);

    expect(fn () => EnsureCanDeleteCounsellorAction::new()->execute(deleteCounsellorDTO($counsellor, $owner)))
        ->not->toThrow(CannotDeleteCounsellorException::class);
});

test('the owner is blocked by an active group therapy affiliation', function () {
    [$counsellor, $owner] = counsellorAndOwner();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
    ]);
    $groupTherapy->counsellors()->attach($counsellor->id, [
        'state' => CounsellorGroupTherapyStateEnum::active->value,
        'role' => CounsellorGroupTherapyRoleEnum::normal->value,
    ]);

    expect(fn () => EnsureCanDeleteCounsellorAction::new()->execute(deleteCounsellorDTO($counsellor, $owner)))
        ->toThrow(CannotDeleteCounsellorException::class, 'You are still an active counsellor on a group therapy. Please leave it before deleting this counsellor account.');
});

test('an inactive group therapy affiliation does not block deletion', function () {
    [$counsellor, $owner] = counsellorAndOwner();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
    ]);
    $groupTherapy->counsellors()->attach($counsellor->id, [
        'state' => CounsellorGroupTherapyStateEnum::inactive->value,
        'role' => CounsellorGroupTherapyRoleEnum::normal->value,
    ]);

    expect(fn () => EnsureCanDeleteCounsellorAction::new()->execute(deleteCounsellorDTO($counsellor, $owner)))
        ->not->toThrow(CannotDeleteCounsellorException::class);
});

test('the owner is blocked by a pending request awaiting their own decision', function () {
    [$counsellor, $owner] = counsellorAndOwner();
    Request::factory()->create([
        'to_id' => $counsellor->id,
        'to_type' => Counsellor::class,
        'type' => RequestTypeEnum::groupTherapy->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => [],
    ]);

    expect(fn () => EnsureCanDeleteCounsellorAction::new()->execute(deleteCounsellorDTO($counsellor, $owner)))
        ->toThrow(CannotDeleteCounsellorException::class, 'You have pending requests awaiting your decision. Please respond to them before deleting this counsellor account.');
});

test('a pending request the counsellor themselves sent does not block deletion', function () {
    [$counsellor, $owner] = counsellorAndOwner();
    Request::factory()->create([
        'from_id' => $counsellor->id,
        'from_type' => Counsellor::class,
        'type' => RequestTypeEnum::counsellor->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => [],
    ]);

    expect(fn () => EnsureCanDeleteCounsellorAction::new()->execute(deleteCounsellorDTO($counsellor, $owner)))
        ->not->toThrow(CannotDeleteCounsellorException::class);
});

test('the owner is blocked by an active organization affiliation', function () {
    [$counsellor, $owner] = counsellorAndOwner();
    $organization = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
    OrganizationCounsellor::factory()->create([
        'organization_id' => $organization->id,
        'counsellor_id' => $counsellor->id,
        'status' => OrganizationCounsellorStatusEnum::active->value,
        'source' => OrganizationCounsellorSourceEnum::invited->value,
    ]);

    expect(fn () => EnsureCanDeleteCounsellorAction::new()->execute(deleteCounsellorDTO($counsellor, $owner)))
        ->toThrow(CannotDeleteCounsellorException::class, 'You have an active organization affiliation. Please end it before deleting this counsellor account.');
});

test('an ended organization affiliation does not block deletion', function () {
    [$counsellor, $owner] = counsellorAndOwner();
    $organization = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
    OrganizationCounsellor::factory()->create([
        'organization_id' => $organization->id,
        'counsellor_id' => $counsellor->id,
        'status' => OrganizationCounsellorStatusEnum::ended->value,
        'source' => OrganizationCounsellorSourceEnum::invited->value,
    ]);

    expect(fn () => EnsureCanDeleteCounsellorAction::new()->execute(deleteCounsellorDTO($counsellor, $owner)))
        ->not->toThrow(CannotDeleteCounsellorException::class);
});
