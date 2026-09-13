<?php

use App\Actions\Request\CreateRequestAction;
use App\DTOs\CreateRequestDTO;
use App\Enums\RequestTypeEnum;
use App\Models\Administrator;
use App\Models\Guardianship;
use App\Models\User;

// TT-4.11c/SCRUM-304: exercises the full RequestService::respondToRequest() pipeline through the
// real HTTP endpoint -- proves EnsureUserCanRespondToRequestAction's authorization for
// ageVerification (isAdmin() only -- no guardian counterpart exists, unlike dobChange) correctly
// gates the request, and that the whole dispatch chain (RespondToRequestAction ->
// RespondToAgeVerificationRequestAction) is wired correctly end to end.

test('an admin can approve an age-verification request via the real endpoint', function () {
    $user = User::factory()->create(['dob' => now()->subYears(30)->toDateString()]);
    $admin = User::factory()->has(Administrator::factory())->create();
    $request = CreateRequestAction::new()->execute(CreateRequestDTO::new()->fromArray([
        'from' => $user, 'to' => null, 'for' => $user,
        'type' => RequestTypeEnum::ageVerification->value,
        'data' => ['attestation' => 'I confirm my dob is accurate.'],
    ]));

    $response = $this->actingAs($admin)->postJson(route('requests.respond', ['requestId' => $request->id]), [
        'response' => 'accepted',
    ]);

    $response->assertSuccessful();
    expect($user->fresh()->dob_verified_at)->not->toBeNull();
});

test('an admin can reject an age-verification request via the real endpoint', function () {
    $user = User::factory()->create();
    $admin = User::factory()->has(Administrator::factory())->create();
    $request = CreateRequestAction::new()->execute(CreateRequestDTO::new()->fromArray([
        'from' => $user, 'to' => null, 'for' => $user,
        'type' => RequestTypeEnum::ageVerification->value,
        'data' => ['attestation' => 'I confirm my dob is accurate.'],
    ]));

    $response = $this->actingAs($admin)->postJson(route('requests.respond', ['requestId' => $request->id]), [
        'response' => 'rejected',
    ]);

    $response->assertSuccessful();
    expect($user->fresh()->dob_verified_at)->toBeNull();
});

// Unlike dobChange, ageVerification has no guardian counterpart -- a guardian of the submitting
// user is not special-cased anywhere in EnsureUserCanRespondToRequestAction for this type, so
// only isAdmin() should ever pass.
test('a guardian of the submitting user still cannot respond to their ageVerification request', function () {
    $user = User::factory()->create();
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $user->id]);
    $request = CreateRequestAction::new()->execute(CreateRequestDTO::new()->fromArray([
        'from' => $user, 'to' => null, 'for' => $user,
        'type' => RequestTypeEnum::ageVerification->value,
        'data' => ['attestation' => 'I confirm my dob is accurate.'],
    ]));

    $response = $this->actingAs($guardian)->postJson(route('requests.respond', ['requestId' => $request->id]), [
        'response' => 'accepted',
    ]);

    $response->assertStatus(422);
    expect($user->fresh()->dob_verified_at)->toBeNull();
});

test('the submitting user themselves cannot respond to their own ageVerification request', function () {
    $user = User::factory()->create();
    $request = CreateRequestAction::new()->execute(CreateRequestDTO::new()->fromArray([
        'from' => $user, 'to' => null, 'for' => $user,
        'type' => RequestTypeEnum::ageVerification->value,
        'data' => ['attestation' => 'I confirm my dob is accurate.'],
    ]));

    $response = $this->actingAs($user)->postJson(route('requests.respond', ['requestId' => $request->id]), [
        'response' => 'accepted',
    ]);

    $response->assertStatus(422);
    expect($user->fresh()->dob_verified_at)->toBeNull();
});
