<?php

use App\Actions\Request\CreateRequestAction;
use App\DTOs\CreateRequestDTO;
use App\Enums\RequestTypeEnum;
use App\Models\Administrator;
use App\Models\Guardianship;
use App\Models\Therapy;
use App\Models\User;

// TT-4.10d/SCRUM-293: exercises the full RequestService::respondToRequest() pipeline through the
// real HTTP endpoint -- proves EnsureUserCanRespondToRequestAction's authorization for dobChange
// (admin, OR any current guardian of the target -- re-verified live, not a stale `to` identity
// match; see TT-4.10f/SCRUM-295's own findings below) correctly gates a dobChange request, and
// that the whole dispatch chain (RespondToRequestAction -> RespondToDobChangeRequestAction) is
// wired correctly end to end.

test('the addressed guardian can approve a dob change request via the real endpoint', function () {
    $minor = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minor->id]);
    $newDob = now()->subYears(30)->toDateString();
    $request = CreateRequestAction::new()->execute(CreateRequestDTO::new()->fromArray([
        'from' => $minor, 'to' => $guardian, 'for' => $minor,
        'type' => RequestTypeEnum::dobChange->value,
        'data' => ['newDob' => $newDob, 'priorDob' => now()->subYears(17)->toDateString()],
    ]));

    $response = $this->actingAs($guardian)->postJson(route('requests.respond', ['requestId' => $request->id]), [
        'response' => 'accepted',
    ]);

    $response->assertSuccessful();
    expect($minor->fresh()->dob->toDateString())->toBe($newDob);
});

test('an admin can approve a dob change request when no guardian exists (null `to`)', function () {
    $minor = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);
    $admin = User::factory()->has(Administrator::factory())->create();
    Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $minor->id]);
    $newDob = now()->subYears(30)->toDateString();
    $request = CreateRequestAction::new()->execute(CreateRequestDTO::new()->fromArray([
        'from' => $minor, 'to' => null, 'for' => $minor,
        'type' => RequestTypeEnum::dobChange->value,
        'data' => ['newDob' => $newDob, 'priorDob' => now()->subYears(17)->toDateString()],
    ]));

    $response = $this->actingAs($admin)->postJson(route('requests.respond', ['requestId' => $request->id]), [
        'response' => 'accepted',
    ]);

    $response->assertSuccessful();
    expect($minor->fresh()->dob->toDateString())->toBe($newDob);
});

// TT-4.10f/SCRUM-295 regression-matrix finding: `to` is fixed to whichever ONE guardian
// EnsureDobChangeIsAllowedAction happened to address the request to at creation time -- a ward
// with more than one active guardian would otherwise leave every guardian but that one unable to
// respond, contradicting this feature's own explicit design ("any one active guardian, no
// unanimity," mirroring GrantVideoConsentAction's identical precedent). Fixed in
// EnsureUserCanRespondToRequestAction (shared across all request types) with a dobChange-specific
// `isGuardianOf($request->for)` check, alongside the existing fixed-`to` check.
test('any one of the ward\'s multiple guardians can approve, not just the one the request was addressed to', function () {
    $minor = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);
    $guardianA = User::factory()->create();
    $guardianB = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardianA->id, 'ward_id' => $minor->id]);
    Guardianship::query()->create(['guardian_id' => $guardianB->id, 'ward_id' => $minor->id]);
    $newDob = now()->subYears(30)->toDateString();
    $request = CreateRequestAction::new()->execute(CreateRequestDTO::new()->fromArray([
        'from' => $minor, 'to' => $guardianA, 'for' => $minor,
        'type' => RequestTypeEnum::dobChange->value,
        'data' => ['newDob' => $newDob, 'priorDob' => now()->subYears(17)->toDateString()],
    ]));

    $response = $this->actingAs($guardianB)->postJson(route('requests.respond', ['requestId' => $request->id]), [
        'response' => 'accepted',
    ]);

    $response->assertSuccessful();
    expect($minor->fresh()->dob->toDateString())->toBe($newDob);
});

// TT-4.10f/SCRUM-295 security-review finding: the fixed `to` guardian is now re-verified live
// (isGuardianOf), not trusted by identity match alone -- a guardian whose guardianship has since
// been revoked must not retain a standing ability to decide their former ward's dob forever.
test('a formerly-guardian `to` cannot respond after their guardianship is deleted', function () {
    $minor = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);
    $guardian = User::factory()->create();
    $guardianship = Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minor->id]);
    $newDob = now()->subYears(30)->toDateString();
    $request = CreateRequestAction::new()->execute(CreateRequestDTO::new()->fromArray([
        'from' => $minor, 'to' => $guardian, 'for' => $minor,
        'type' => RequestTypeEnum::dobChange->value,
        'data' => ['newDob' => $newDob, 'priorDob' => now()->subYears(17)->toDateString()],
    ]));

    $guardianship->delete();

    $response = $this->actingAs($guardian)->postJson(route('requests.respond', ['requestId' => $request->id]), [
        'response' => 'accepted',
    ]);

    $response->assertStatus(422);
    expect($minor->fresh()->dob->toDateString())->not->toBe($newDob);
});

test('an unrelated user cannot respond to someone else\'s dob change request', function () {
    $minor = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minor->id]);
    $unrelatedUser = User::factory()->create();
    $originalDob = $minor->dob->toDateString();
    $request = CreateRequestAction::new()->execute(CreateRequestDTO::new()->fromArray([
        'from' => $minor, 'to' => $guardian, 'for' => $minor,
        'type' => RequestTypeEnum::dobChange->value,
        'data' => ['newDob' => now()->subYears(30)->toDateString(), 'priorDob' => $originalDob],
    ]));

    $response = $this->actingAs($unrelatedUser)->postJson(route('requests.respond', ['requestId' => $request->id]), [
        'response' => 'accepted',
    ]);

    $response->assertStatus(422);
    expect($minor->fresh()->dob->toDateString())->toBe($originalDob);
});
