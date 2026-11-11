<?php

use App\Actions\Link\ChangeLinkStatusAction;
use App\Actions\Organization\EnsureNotOrganizationMemberAction;
use App\Enums\LinkStateEnum;
use App\Enums\LinkTypeEnum;
use App\Enums\OrganizationMemberSourceEnum;
use App\Enums\OrganizationMemberStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Models\Link;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Request;
use App\Models\User;
use Illuminate\Support\Str;

// SCRUM-415: a used link stays used -- switching it back on is refused (any type), an organisation join
// link is never switched back on, and someone already a member can't apply again. Helpers are file-local
// and lsu-prefixed (--parallel).

function lsuLink(User $creator, string $type, array $attributes = []): Link
{
    return Link::unguarded(fn () => Link::create([
        'uuid' => (string) Str::uuid(),
        'type' => $type,
        'state' => LinkStateEnum::active->value,
        'addedby_type' => User::class,
        'addedby_id' => $creator->id,
        ...$attributes,
    ]));
}

function lsuOrganization(): Organization
{
    return Organization::factory()->create(['is_provider' => false, 'is_consumer' => true, 'verified_at' => now(), 'self_apply_enabled' => true]);
}

test('a used link can\'t be switched back on, one or several at a time', function () {
    $creator = User::factory()->adult()->create();
    $used = lsuLink($creator, LinkTypeEnum::guardianship->value);
    $used->markUsed();
    $switchedOff = lsuLink($creator, LinkTypeEnum::guardianship->value, ['state' => LinkStateEnum::inactive->value]);

    expect($used->fresh()->used_at)->not->toBeNull()
        ->and($used->fresh()->state)->toBe(LinkStateEnum::inactive->value);

    $this->actingAs($creator)->postJson("/api/links/{$used->id}/status")
        ->assertStatus(422)->assertJsonPath('message', ChangeLinkStatusAction::CANNOT_REACTIVATE);
    expect($used->fresh()->state)->toBe(LinkStateEnum::inactive->value);

    // All or none: the switched-off one stays off too.
    $this->postJson('/api/links/status/multiple', ['linksData' => [['id' => $switchedOff->id], ['id' => $used->id]]])
        ->assertStatus(422);
    expect($switchedOff->fresh()->state)->toBe(LinkStateEnum::inactive->value);

    // One its creator switched off (never used) still switches back on.
    $this->postJson("/api/links/{$switchedOff->id}/status")->assertOk();
    expect($switchedOff->fresh()->state)->toBe(LinkStateEnum::active->value);
});

test('an organisation join link is never switched back on, even one used before used_at existed', function () {
    $organization = lsuOrganization();
    $admin = User::factory()->adult()->create();
    $legacyUsed = lsuLink($admin, LinkTypeEnum::organizationSelfApply->value, [
        'state' => LinkStateEnum::inactive->value, 'for_type' => Organization::class, 'for_id' => $organization->id,
    ]);

    $this->actingAs($admin)->postJson("/api/links/{$legacyUsed->id}/status")
        ->assertStatus(422)->assertJsonPath('message', ChangeLinkStatusAction::CANNOT_REACTIVATE);
    expect($legacyUsed->fresh()->state)->toBe(LinkStateEnum::inactive->value);
});

test('someone already a member can\'t apply to the organisation again', function () {
    $organization = lsuOrganization();
    $member = User::factory()->adult()->create();
    OrganizationMember::create(['organization_id' => $organization->id, 'user_id' => $member->id, 'status' => OrganizationMemberStatusEnum::active->value, 'source' => OrganizationMemberSourceEnum::applied->value]);

    $this->actingAs($member)->postJson("/organizations/{$organization->id}/member-applications")
        ->assertStatus(422)->assertJsonPath('message', EnsureNotOrganizationMemberAction::ALREADY_MEMBER);

    expect(Request::query()->whereFor($organization)->where('type', RequestTypeEnum::organizationMemberApplication->value)->exists())->toBeFalse();
});

test('deleting several links is all or none -- one that isn\'t theirs leaves the others in place', function () {
    $creator = User::factory()->adult()->create();
    $own = lsuLink($creator, LinkTypeEnum::guardianship->value);
    $someoneElses = lsuLink(User::factory()->adult()->create(), LinkTypeEnum::guardianship->value);

    $this->actingAs($creator)->postJson('/api/links/delete/multiple', ['linksData' => [['id' => $own->id], ['id' => $someoneElses->id]]])
        ->assertStatus(422);

    expect(Link::find($own->id))->not->toBeNull()
        ->and(Link::find($someoneElses->id))->not->toBeNull();
});
