<?php

use App\Enums\LinkStateEnum;
use App\Enums\LinkTypeEnum;
use App\Enums\OrganizationAdminRoleEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Models\Link;
use App\Models\Organization;
use App\Models\Request;
use App\Models\User;

test('an org admin can generate a self-apply link via the real route', function () {
    $organization = Organization::factory()->create(['is_provider' => false, 'is_consumer' => true, 'verified_at' => now(), 'self_apply_enabled' => true]);
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);

    $this->actingAs($owner);

    $response = $this->postJson('/api/links', [
        'type' => LinkTypeEnum::organizationSelfApply->value,
        'addedbyType' => 'User',
        'addedbyId' => $owner->id,
        'forType' => 'Organization',
        'forId' => $organization->id,
    ]);

    $response->assertOk();
    expect(Link::where('type', LinkTypeEnum::organizationSelfApply->value)->where('for_id', $organization->id)->exists())->toBeTrue();
});

test('a user with no admin relationship to the organization cannot generate a self-apply link via the real route', function () {
    $organization = Organization::factory()->create(['is_provider' => false, 'is_consumer' => true, 'verified_at' => now(), 'self_apply_enabled' => true]);
    $outsider = User::factory()->create();

    $this->actingAs($outsider);

    $response = $this->postJson('/api/links', [
        'type' => LinkTypeEnum::organizationSelfApply->value,
        'addedbyType' => 'User',
        'addedbyId' => $outsider->id,
        'forType' => 'Organization',
        'forId' => $organization->id,
    ]);

    $response->assertStatus(403);
});

test('visiting a self-apply link creates a pending member application via the real route', function () {
    $organization = Organization::factory()->create(['is_provider' => false, 'is_consumer' => true, 'verified_at' => now(), 'self_apply_enabled' => true]);
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
    $applicant = User::factory()->create();

    $this->actingAs($owner);
    $this->postJson('/api/links', [
        'type' => LinkTypeEnum::organizationSelfApply->value,
        'addedbyType' => 'User',
        'addedbyId' => $owner->id,
        'forType' => 'Organization',
        'forId' => $organization->id,
    ]);
    $link = Link::where('type', LinkTypeEnum::organizationSelfApply->value)->where('for_id', $organization->id)->first();

    $this->actingAs($applicant);
    $this->get("/links/{$link->uuid}")->assertRedirect(route('home'));

    $request = Request::query()->whereFor($organization)->first();
    expect($request->type)->toBe(RequestTypeEnum::organizationMemberApplication->value);
    expect($request->status)->toBe(RequestStatusEnum::pending->value);
    expect($link->fresh()->state)->toBe(LinkStateEnum::inactive->value);
});

test('the org admin who generated the link cannot use it themselves', function () {
    $organization = Organization::factory()->create(['is_provider' => false, 'is_consumer' => true, 'verified_at' => now(), 'self_apply_enabled' => true]);
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);

    $this->actingAs($owner);
    $this->postJson('/api/links', [
        'type' => LinkTypeEnum::organizationSelfApply->value,
        'addedbyType' => 'User',
        'addedbyId' => $owner->id,
        'forType' => 'Organization',
        'forId' => $organization->id,
    ]);
    $link = Link::where('type', LinkTypeEnum::organizationSelfApply->value)->where('for_id', $organization->id)->first();

    $this->get("/links/{$link->uuid}");

    expect(Request::query()->whereFor($organization)->exists())->toBeFalse();
    expect($link->fresh()->state)->toBe(LinkStateEnum::active->value);
});
