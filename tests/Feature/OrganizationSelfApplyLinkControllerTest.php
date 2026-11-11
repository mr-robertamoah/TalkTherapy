<?php

use App\Actions\Link\EnsureUserCanCreateOrganizationSelfApplyLinkAction;
use App\Actions\Link\PerformOrganizationSelfApplyLinkAction;
use App\Enums\LinkStateEnum;
use App\Enums\LinkTypeEnum;
use App\Enums\OrganizationAdminRoleEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Models\Administrator;
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

    // SCRUM-415: opening it shows the organisation and changes nothing; confirming applies.
    $this->actingAs($applicant);
    $this->get("/links/{$link->uuid}")->assertOk()
        ->assertInertia(fn ($page) => $page->component('Links/OrganizationJoin')
            ->where('uuid', $link->uuid)
            ->where('organization.name', $organization->name)
            ->missing('refusal'));
    expect(Request::query()->whereFor($organization)->exists())->toBeFalse()
        ->and($link->fresh()->state)->toBe(LinkStateEnum::active->value);

    $this->postJson("/api/links/{$link->uuid}/join")->assertOk()
        ->assertJsonPath('message', PerformOrganizationSelfApplyLinkAction::APPLIED);

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

    $this->get("/links/{$link->uuid}")->assertOk()
        ->assertInertia(fn ($page) => $page->component('Links/OrganizationJoin')
            ->where('refusal', 'You cannot use a link you created.')
            ->missing('organization'));
    $this->postJson("/api/links/{$link->uuid}/join")->assertStatus(422);

    expect(Request::query()->whereFor($organization)->exists())->toBeFalse();
    expect($link->fresh()->state)->toBe(LinkStateEnum::active->value);
});

// SCRUM-214: the link is the admin's own, and only for an organization people can actually join through it.
function oslPost($test, User $admin, Organization $organization, ?int $addedbyId = null)
{
    return $test->actingAs($admin)->postJson('/api/links', [
        'type' => LinkTypeEnum::organizationSelfApply->value,
        'addedbyType' => 'User',
        'addedbyId' => $addedbyId ?? $admin->id,
        'forType' => 'Organization',
        'forId' => $organization->id,
    ]);
}

test('an admin can\'t create a self-apply link in someone else\'s name', function () {
    $organization = Organization::factory()->create(['is_provider' => false, 'is_consumer' => true, 'verified_at' => now(), 'self_apply_enabled' => true]);
    [$owner, $other] = [User::factory()->create(), User::factory()->create()];
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);

    // Refused before this type's own checks, by the generic addedby validation (EnsureAddedbyIsValidAction).
    oslPost($this, $owner, $organization, $other->id)->assertStatus(422);
    expect(Link::query()->where('type', LinkTypeEnum::organizationSelfApply->value)->exists())->toBeFalse();

    // A platform administrator may name anyone as addedby elsewhere -- not on a self-apply link.
    $platformAdmin = User::factory()->has(Administrator::factory())->create();
    $organization->admins()->attach($platformAdmin->id, ['role' => OrganizationAdminRoleEnum::admin->value]);
    oslPost($this, $platformAdmin, $organization, $other->id)->assertStatus(403);
    expect(Link::query()->where('type', LinkTypeEnum::organizationSelfApply->value)->exists())->toBeFalse();
});

test('no self-apply link while it couldn\'t work -- self-apply off, not verified, or not a consumer organization', function () {
    $owner = User::factory()->create();
    foreach ([
        ['self_apply_enabled' => false],
        ['verified_at' => null],
        ['is_consumer' => false, 'is_provider' => true],
    ] as $state) {
        $organization = Organization::factory()->create(['is_provider' => false, 'is_consumer' => true, 'verified_at' => now(), 'self_apply_enabled' => true, ...$state]);
        $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);

        oslPost($this, $owner, $organization)->assertStatus(422)
            ->assertJsonPath('message', EnsureUserCanCreateOrganizationSelfApplyLinkAction::NOT_ACCEPTING);
    }
    expect(Link::query()->where('type', LinkTypeEnum::organizationSelfApply->value)->exists())->toBeFalse();
});
