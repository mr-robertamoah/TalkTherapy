<?php

use App\Actions\Request\CreateRequestAction;
use App\DTOs\CreateRequestDTO;
use App\Enums\RequestTypeEnum;
use App\Models\Administrator;
use App\Models\Guardianship;
use App\Models\Therapy;
use App\Models\User;

// TT-4.10d/SCRUM-293: exercises the full RequestService::respondToRequest() pipeline through the
// real HTTP endpoint -- proves EnsureUserCanRespondToRequestAction's EXISTING authorization
// (admin, OR the specific addressed `to` guardian, OR any admin when `to` is null) correctly
// gates a dobChange request with no new authorization code, and that the whole dispatch chain
// (RespondToRequestAction -> RespondToDobChangeRequestAction) is wired correctly end to end.

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
