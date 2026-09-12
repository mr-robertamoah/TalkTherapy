<?php

use App\Enums\RequestTypeEnum;
use App\Models\Administrator;
use App\Models\Guardianship;
use App\Models\Request;
use App\Models\User;

// TT-4.10c/SCRUM-292: the admin update path (UpdateUserAction) must route a boundary-crossing dob
// edit through the same shared EnsureDobChangeIsAllowedAction as ProfileController::update().

test('an admin can freely update dob for a user with no qualifying relationship', function () {
    $admin = User::factory()->has(Administrator::factory())->create();
    $target = User::factory()->create(['dob' => now()->subYears(17)->toDateString()]);

    $response = $this->actingAs($admin)->postJson(route('admin.users.update', ['userId' => $target->id]), [
        'firstName' => $target->firstName,
        'dob' => now()->subYears(30)->toDateString(),
    ]);

    $response->assertSuccessful();
    expect($target->fresh()->dob->toDateString())->toBe(now()->subYears(30)->toDateString());
});

test('an admin\'s boundary-crossing dob edit for a qualifying user is deferred, but other submitted fields still save', function () {
    $admin = User::factory()->has(Administrator::factory())->create();
    $minor = User::factory()->create(['dob' => now()->subYears(17)->toDateString(), 'firstName' => 'Original']);
    $guardian = User::factory()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minor->id]);

    $response = $this->actingAs($admin)->postJson(route('admin.users.update', ['userId' => $minor->id]), [
        'firstName' => 'Updated By Admin',
        'dob' => now()->subYears(30)->toDateString(),
    ]);

    $response->assertSuccessful();

    $fresh = $minor->fresh();
    expect($fresh->firstName)->toBe('Updated By Admin');
    expect($fresh->dob->toDateString())->toBe(now()->subYears(17)->toDateString());

    $request = Request::query()->whereType(RequestTypeEnum::dobChange->value)->first();
    expect($request)->not->toBeNull();
    expect($request->from_id)->toBe($admin->id);
    expect($request->for_id)->toBe($minor->id);
});

// Bundled fix (TT-4.10c/SCRUM-292): UpdateUserAction previously ran `new Carbon($updateUserDTO->dob)`
// unconditionally -- Carbon treats a null argument as "now", so an admin update that didn't touch
// dob at all would have silently reset the target's dob to today.
test('an admin update that omits dob entirely does not reset the target\'s existing dob', function () {
    $admin = User::factory()->has(Administrator::factory())->create();
    $originalDob = now()->subYears(25)->toDateString();
    $target = User::factory()->create(['dob' => $originalDob]);

    $response = $this->actingAs($admin)->postJson(route('admin.users.update', ['userId' => $target->id]), [
        'firstName' => 'Name Only Update',
    ]);

    $response->assertSuccessful();
    expect($target->fresh()->dob->toDateString())->toBe($originalDob);
});
