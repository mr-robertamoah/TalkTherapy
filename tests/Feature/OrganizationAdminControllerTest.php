<?php

use App\Enums\OrganizationAdminRoleEnum;
use App\Models\Organization;
use App\Models\User;

test('an owner can add a new admin via the real route', function () {
    $organization = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
    $newAdmin = User::factory()->create();

    $this->actingAs($owner);

    $response = $this->postJson("/organizations/{$organization->id}/admins", ['userId' => $newAdmin->id]);

    $response->assertOk();
    expect($organization->isAdministeredBy($newAdmin))->toBeTrue();
    expect(collect($response->json('admins'))->firstWhere('id', $newAdmin->id)['role'])->toBe(OrganizationAdminRoleEnum::admin->value);
});

test('a plain admin cannot add a new admin via the real route', function () {
    $organization = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
    $plainAdmin = User::factory()->create();
    $organization->admins()->attach($plainAdmin->id, ['role' => OrganizationAdminRoleEnum::admin->value]);
    $newAdmin = User::factory()->create();

    $this->actingAs($plainAdmin);

    $response = $this->postJson("/organizations/{$organization->id}/admins", ['userId' => $newAdmin->id]);

    $response->assertStatus(403);
    expect($organization->isAdministeredBy($newAdmin))->toBeFalse();
});

// SCRUM-176: previously, a nonexistent userId 422'd at the FormRequest validation layer before
// the owner-authorization check ever ran, letting a non-owner distinguish a real userId (a
// service-layer 403) from a fake one (a validation 422) -- a mild existence oracle. Both must now
// produce the exact same response.
test('a plain admin gets the same failure response for a real userId and a nonexistent one', function () {
    $organization = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
    $plainAdmin = User::factory()->create();
    $organization->admins()->attach($plainAdmin->id, ['role' => OrganizationAdminRoleEnum::admin->value]);
    $realTarget = User::factory()->create();

    $this->actingAs($plainAdmin);

    $realResponse = $this->postJson("/organizations/{$organization->id}/admins", ['userId' => $realTarget->id]);
    $fakeResponse = $this->postJson("/organizations/{$organization->id}/admins", ['userId' => $realTarget->id + 999999]);

    $realResponse->assertStatus(403);
    $fakeResponse->assertStatus(403);
    expect($fakeResponse->json('message'))->toBe($realResponse->json('message'));
    expect($organization->isAdministeredBy($realTarget))->toBeFalse();
});

test('an owner still gets a distinct not-found error for a genuinely nonexistent userId', function () {
    $organization = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);

    $this->actingAs($owner);

    $this->postJson("/organizations/{$organization->id}/admins", ['userId' => 999999])
        ->assertStatus(404);
});

test('an owner can promote a plain admin to owner via the real route', function () {
    $organization = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
    $plainAdmin = User::factory()->create();
    $organization->admins()->attach($plainAdmin->id, ['role' => OrganizationAdminRoleEnum::admin->value]);

    $this->actingAs($owner);

    $response = $this->patchJson("/organizations/{$organization->id}/admins/{$plainAdmin->id}", [
        'role' => OrganizationAdminRoleEnum::owner->value,
    ]);

    $response->assertOk();
    expect($organization->admins()->whereKey($plainAdmin->id)->first()->pivot->role)->toBe(OrganizationAdminRoleEnum::owner->value);
});

test('demoting the last owner via the real route is rejected', function () {
    $organization = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);

    $this->actingAs($owner);

    $response = $this->patchJson("/organizations/{$organization->id}/admins/{$owner->id}", [
        'role' => OrganizationAdminRoleEnum::admin->value,
    ]);

    $response->assertStatus(422);
    expect($organization->admins()->whereKey($owner->id)->first()->pivot->role)->toBe(OrganizationAdminRoleEnum::owner->value);
});

test('an owner can remove a plain admin via the real route', function () {
    $organization = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
    $plainAdmin = User::factory()->create();
    $organization->admins()->attach($plainAdmin->id, ['role' => OrganizationAdminRoleEnum::admin->value]);

    $this->actingAs($owner);

    $response = $this->deleteJson("/organizations/{$organization->id}/admins/{$plainAdmin->id}");

    $response->assertOk();
    expect($organization->isAdministeredBy($plainAdmin))->toBeFalse();
});

test('removing the last owner via the real route is rejected', function () {
    $organization = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);

    $this->actingAs($owner);

    $response = $this->deleteJson("/organizations/{$organization->id}/admins/{$owner->id}");

    $response->assertStatus(422);
    expect($organization->isAdministeredBy($owner))->toBeTrue();
});

test('a guest cannot manage organization admins', function () {
    $organization = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
    $target = User::factory()->create();

    $this->postJson("/organizations/{$organization->id}/admins", ['userId' => $target->id])->assertStatus(401);
    $this->patchJson("/organizations/{$organization->id}/admins/{$target->id}", ['role' => OrganizationAdminRoleEnum::admin->value])->assertStatus(401);
    $this->deleteJson("/organizations/{$organization->id}/admins/{$target->id}")->assertStatus(401);
});

// SCRUM-178: a nonexistent organizationId and a real one the caller doesn't own previously
// returned different statuses (404 vs 403) on these admin-management routes -- the same oracle
// SCRUM-170 closed on the read-only org endpoints, closed here the same way.
test('a nonexistent organization and a real one the caller does not own fail identically on the admin-management routes', function () {
    $organization = Organization::factory()->create(['is_provider' => true, 'verified_at' => now()]);
    $plainAdmin = User::factory()->create();
    $organization->admins()->attach($plainAdmin->id, ['role' => OrganizationAdminRoleEnum::admin->value]);
    $target = User::factory()->create();

    $this->actingAs($plainAdmin);

    $addFake = $this->postJson('/organizations/999999/admins', ['userId' => $target->id]);
    $addReal = $this->postJson("/organizations/{$organization->id}/admins", ['userId' => $target->id]);
    expect($addFake->getStatusCode())->toBe($addReal->getStatusCode());
    expect($addFake->json('message'))->toBe($addReal->json('message'));

    $updateFake = $this->patchJson("/organizations/999999/admins/{$plainAdmin->id}", ['role' => OrganizationAdminRoleEnum::owner->value]);
    $updateReal = $this->patchJson("/organizations/{$organization->id}/admins/{$plainAdmin->id}", ['role' => OrganizationAdminRoleEnum::owner->value]);
    expect($updateFake->getStatusCode())->toBe($updateReal->getStatusCode());
    expect($updateFake->json('message'))->toBe($updateReal->json('message'));

    $removeFake = $this->deleteJson("/organizations/999999/admins/{$plainAdmin->id}");
    $removeReal = $this->deleteJson("/organizations/{$organization->id}/admins/{$plainAdmin->id}");
    expect($removeFake->getStatusCode())->toBe($removeReal->getStatusCode());
    expect($removeFake->json('message'))->toBe($removeReal->json('message'));
});
