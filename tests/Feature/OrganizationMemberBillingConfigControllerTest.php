<?php

use App\Enums\OrganizationAdminRoleEnum;
use App\Enums\OrganizationMemberBillingModeEnum;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;

// SCRUM-125 security review flagged the controller's original `(bool) $request->x` cast as a
// silent-flip risk for a string "false" body value. Verified directly against Laravel's own
// `boolean` validation rule (vendor/laravel/framework/.../ValidatesAttributes.php:496): its
// accepted set is `[true, false, 0, 1, '0', '1']` -- the *word* "false"/"true" is NOT in it, so
// that specific input is actually rejected at validation (422) before ever reaching the cast.
// Confirmed here rather than assumed. The `$request->boolean()` change is still kept as a
// harmless, more idiomatic defense-in-depth improvement (correct even if the validation rule
// changes later), not a fix for a currently-reachable bug.
test('a string "false" body value is rejected by validation, not silently coerced', function () {
    $organization = Organization::factory()->create(['is_provider' => false, 'is_consumer' => true, 'verified_at' => now()]);
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
    $member = OrganizationMember::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => User::factory(),
    ]);

    $this->actingAs($owner);

    $response = $this->postJson("/organization-members/{$member->id}/billing-configs", [
        'mode' => OrganizationMemberBillingModeEnum::retainer->value,
        'includeGroupTherapies' => 'false',
    ]);

    $response->assertStatus(422);
    expect($member->currentBillingConfig())->toBeNull();
});

test('the validation-accepted string "0" is correctly persisted as false', function () {
    $organization = Organization::factory()->create(['is_provider' => false, 'is_consumer' => true, 'verified_at' => now()]);
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
    $member = OrganizationMember::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => User::factory(),
    ]);

    $this->actingAs($owner);

    $response = $this->postJson("/organization-members/{$member->id}/billing-configs", [
        'mode' => OrganizationMemberBillingModeEnum::retainer->value,
        'includeGroupTherapies' => '0',
    ]);

    $response->assertOk();
    expect($member->currentBillingConfig()->include_group_therapies)->toBeFalse();
});

test('a user with no admin relationship to the organization is rejected via the real route', function () {
    $organization = Organization::factory()->create(['is_provider' => false, 'is_consumer' => true, 'verified_at' => now()]);
    $member = OrganizationMember::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => User::factory(),
    ]);

    $outsider = User::factory()->create();
    $this->actingAs($outsider);

    $response = $this->postJson("/organization-members/{$member->id}/billing-configs", [
        'mode' => OrganizationMemberBillingModeEnum::retainer->value,
        'includeGroupTherapies' => true,
    ]);

    $response->assertStatus(403);
});
