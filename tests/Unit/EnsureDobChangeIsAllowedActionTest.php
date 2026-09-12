<?php

use App\Actions\User\EnsureDobChangeIsAllowedAction;
use App\DTOs\EnsureDobChangeIsAllowedDTO;
use App\Enums\RequestTypeEnum;
use App\Exceptions\DobChangeRequiresApprovalException;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\Request;
use App\Models\Therapy;
use App\Models\User;

// TT-4.10c/SCRUM-292: relationship-gated, both-directions boundary-crossing gate. A user with no
// Guardianship-as-ward row and no Therapy/GroupTherapy client snapshot edits dob freely, unchanged
// from today; a qualifying user's dob edit is blocked (a pending approval Request created instead)
// only when it would actually flip their current minor/adult status.

function executeDobChangeGate(User $user, ?string $newDob, ?User $actor = null): void
{
    EnsureDobChangeIsAllowedAction::new()->execute(EnsureDobChangeIsAllowedDTO::new()->fromArray([
        'user' => $user,
        'actor' => $actor ?: $user,
        'newDob' => $newDob,
    ]));
}

test('a user with no qualifying relationship can freely cross the minor/adult boundary', function () {
    $user = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);

    expect(fn () => executeDobChangeGate($user, now()->subYears(30)->toDateString()))
        ->not->toThrow(DobChangeRequiresApprovalException::class);

    expect(Request::query()->whereType(RequestTypeEnum::dobChange->value)->count())->toBe(0);
});

test('a qualifying user (has a guardian) whose edit does NOT cross the boundary is unrestricted', function () {
    $adult = User::factory()->create(['dob' => now()->subYears(25)->toDateString()]);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $adult->id]);

    expect(fn () => executeDobChangeGate($adult, now()->subYears(30)->toDateString()))
        ->not->toThrow(DobChangeRequiresApprovalException::class);

    expect(Request::query()->whereType(RequestTypeEnum::dobChange->value)->count())->toBe(0);
});

test('a qualifying user (has a guardian) crossing minor-to-adult is blocked and a request is created with the guardian as `to`', function () {
    $minor = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minor->id]);

    expect(fn () => executeDobChangeGate($minor, now()->subYears(30)->toDateString(), $minor))
        ->toThrow(DobChangeRequiresApprovalException::class);

    $request = Request::query()->whereType(RequestTypeEnum::dobChange->value)->first();
    expect($request)->not->toBeNull();
    expect($request->from_id)->toBe($minor->id);
    expect($request->to_id)->toBe($guardian->id);
    expect($request->to_type)->toBe(User::class);
    expect($request->for_id)->toBe($minor->id);
    expect($request->status)->toBe('PENDING');
});

test('a qualifying user crossing adult-to-minor (the reverse direction) is also blocked', function () {
    $adult = User::factory()->create(['dob' => now()->subYears(30)->toDateString()]);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $adult->id]);

    expect(fn () => executeDobChangeGate($adult, now()->subYears(10)->toDateString(), $adult))
        ->toThrow(DobChangeRequiresApprovalException::class);

    expect(Request::query()->whereType(RequestTypeEnum::dobChange->value)->count())->toBe(1);
});

test('clearing dob entirely counts as becoming a minor for an adult with a qualifying relationship', function () {
    $adult = User::factory()->create(['dob' => now()->subYears(30)->toDateString()]);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $adult->id]);

    expect(fn () => executeDobChangeGate($adult, null, $adult))
        ->toThrow(DobChangeRequiresApprovalException::class);
});

test('a qualifying relationship via a Therapy client record (no Guardianship) is also gated, with a null `to` (no guardian exists)', function () {
    $minor = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);
    Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $minor->id]);

    expect(fn () => executeDobChangeGate($minor, now()->subYears(30)->toDateString(), $minor))
        ->toThrow(DobChangeRequiresApprovalException::class);

    $request = Request::query()->whereType(RequestTypeEnum::dobChange->value)->first();
    expect($request->to_id)->toBeNull();
    expect($request->to_type)->toBeNull();
});

test('a qualifying relationship via a GroupTherapy client record (User addedby) is also gated', function () {
    $minor = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);
    GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $minor->id]);

    expect(fn () => executeDobChangeGate($minor, now()->subYears(30)->toDateString(), $minor))
        ->toThrow(DobChangeRequiresApprovalException::class);
});

test('a Counsellor-created GroupTherapy does not itself count as a qualifying relationship for the counsellor', function () {
    $counsellorUser = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id]);

    expect(fn () => executeDobChangeGate($counsellorUser, now()->subYears(30)->toDateString()))
        ->not->toThrow(DobChangeRequiresApprovalException::class);
});

test('an unrelated field-only resubmission of the SAME dob is never gated, even for a qualifying user', function () {
    $minor = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minor->id]);

    expect(fn () => executeDobChangeGate($minor, $minor->dob->toDateString()))
        ->not->toThrow(DobChangeRequiresApprovalException::class);

    expect(Request::query()->whereType(RequestTypeEnum::dobChange->value)->count())->toBe(0);
});

test('a second gated attempt reuses the already-outstanding pending request instead of creating a duplicate, refreshing its proposed newDob', function () {
    $minor = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minor->id]);

    expect(fn () => executeDobChangeGate($minor, now()->subYears(30)->toDateString(), $minor))
        ->toThrow(DobChangeRequiresApprovalException::class);
    expect(fn () => executeDobChangeGate($minor, now()->subYears(25)->toDateString(), $minor))
        ->toThrow(DobChangeRequiresApprovalException::class);

    $requests = Request::query()->whereType(RequestTypeEnum::dobChange->value)->get();
    expect($requests)->toHaveCount(1);
    // The reused request's data reflects the LATEST attempt, not the stale first one -- a future
    // approval must act on what the user most recently asked for.
    expect($requests->first()->data['newDob'])->toBe(now()->subYears(25)->toDateString());
    expect($requests->first()->data['priorDob'])->toBe(now()->subYears(17)->toDateString());
});
