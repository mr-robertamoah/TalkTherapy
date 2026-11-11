<?php

use App\Actions\Link\ChangeLinkStatusAction;
use App\Actions\Organization\EnsureNotOrganizationMemberAction;
use App\Enums\LinkStateEnum;
use App\Enums\LinkTypeEnum;
use App\Enums\OrganizationAdminRoleEnum;
use App\Enums\OrganizationMemberSourceEnum;
use App\Enums\OrganizationMemberStatusEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Exceptions\LinkException;
use App\Http\Resources\OrganizationJoinLinkResource;
use App\Models\Link;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Request;
use App\Models\User;
use Illuminate\Support\Str;

// SCRUM-415: opening an organisation join link shows a confirm page and changes nothing; confirming
// applies once; refusals say why on the page (never a flash) and show nothing of the organisation; the
// organisation's admins list and revoke its links; a member sees their pending applications; someone who
// signs up from a link is offered it again. Helpers are file-local and ojl-prefixed (--parallel).

function ojlOrganization(array $attributes = []): Organization
{
    return Organization::factory()->create([
        'is_provider' => false, 'is_consumer' => true, 'verified_at' => now(), 'self_apply_enabled' => true,
        'name' => 'Harbour Wellbeing', 'description' => 'Support for harbour workers.',
        ...$attributes,
    ]);
}

function ojlAdmin(Organization $organization): User
{
    $admin = User::factory()->adult()->create();
    $organization->admins()->attach($admin->id, ['role' => OrganizationAdminRoleEnum::owner->value]);

    return $admin;
}

function ojlLink(User $admin, Organization $organization): Link
{
    return Link::unguarded(fn () => Link::create([
        'uuid' => (string) Str::uuid(),
        'type' => LinkTypeEnum::organizationSelfApply->value,
        'state' => LinkStateEnum::active->value,
        'addedby_type' => User::class,
        'addedby_id' => $admin->id,
        'for_type' => Organization::class,
        'for_id' => $organization->id,
    ]));
}

function ojlRefusal($test, User $user, Link $link): string
{
    $response = $test->actingAs($user)->get("/links/{$link->uuid}")->assertOk();
    $response->assertInertia(fn ($page) => $page->component('Links/OrganizationJoin')->missing('organization')->missing('uuid'));
    expect(session('alert'))->toBeNull();

    return $response->viewData('page')['props']['refusal'];
}

test('confirming applies once: a second person with the same link is refused, and no flash is used', function () {
    $organization = ojlOrganization();
    $link = ojlLink(ojlAdmin($organization), $organization);
    [$first, $second] = User::factory()->adult()->count(2)->create();

    $this->actingAs($first)->postJson("/api/links/{$link->uuid}/join")->assertOk();
    expect(session('alert'))->toBeNull();

    $this->actingAs($second)->postJson("/api/links/{$link->uuid}/join")
        ->assertStatus(422)->assertJsonPath('message', LinkException::INACTIVE);

    expect(Request::query()->whereFor($organization)->count())->toBe(1)
        ->and($link->fresh()->used_at)->not->toBeNull()
        ->and(ojlRefusal($this, $second, $link))->toBe(LinkException::INACTIVE);
});

test('an organisation not taking members is refused with one generic reason, whichever setting is off', function () {
    foreach ([['self_apply_enabled' => false], ['verified_at' => null], ['is_consumer' => false, 'is_provider' => true]] as $state) {
        $organization = ojlOrganization($state);
        $link = ojlLink(ojlAdmin($organization), $organization);

        expect(ojlRefusal($this, User::factory()->adult()->create(), $link))->toBe('You cannot apply to this organization.');
    }
});

test('a member, or someone with an application waiting, is told so before confirming', function () {
    $organization = ojlOrganization();
    $link = ojlLink(ojlAdmin($organization), $organization);

    $member = User::factory()->adult()->create();
    OrganizationMember::create(['organization_id' => $organization->id, 'user_id' => $member->id, 'status' => OrganizationMemberStatusEnum::active->value, 'source' => OrganizationMemberSourceEnum::invited->value]);
    expect(ojlRefusal($this, $member, $link))->toBe(EnsureNotOrganizationMemberAction::ALREADY_MEMBER);

    $applicant = User::factory()->adult()->create();
    $this->actingAs($applicant)->postJson("/organizations/{$organization->id}/member-applications")->assertOk();
    expect(ojlRefusal($this, $applicant, $link))->toBe('There is already a pending request between this organization and user.')
        ->and($link->fresh()->state)->toBe(LinkStateEnum::active->value);
});

test('the organisation\'s admins list every admin\'s join links, with what became of each', function () {
    $organization = ojlOrganization();
    $owner = ojlAdmin($organization);
    $coAdmin = ojlAdmin($organization);
    $active = ojlLink($owner, $organization);
    $used = ojlLink($owner, $organization);
    $used->markUsed();
    $revoked = ojlLink($coAdmin, $organization);
    $revoked->deactivate();
    $otherOrganization = ojlOrganization();
    ojlLink(ojlAdmin($otherOrganization), $otherOrganization);

    $links = collect($this->actingAs($coAdmin)->getJson("/organizations/{$organization->id}/join-links")->assertOk()->json('data'))->keyBy('id');

    expect($links)->toHaveCount(3)
        ->and($links[$active->id]['status'])->toBe(OrganizationJoinLinkResource::ACTIVE)
        ->and($links[$active->id]['uuid'])->toBe($active->uuid)
        ->and($links[$active->id]['createdBy'])->toBe($owner->name)
        ->and($links[$used->id]['status'])->toBe(OrganizationJoinLinkResource::USED)
        ->and($links[$used->id]['uuid'])->toBeNull()
        ->and($links[$revoked->id]['status'])->toBe(OrganizationJoinLinkResource::REVOKED);
});

test('only the organisation\'s admins see or revoke its links -- the same 403 whether or not it exists', function () {
    $organization = ojlOrganization();
    $link = ojlLink(ojlAdmin($organization), $organization);
    $outsider = User::factory()->adult()->create();
    $missing = Organization::max('id') + 1000;

    foreach ([$organization->id, $missing] as $organizationId) {
        $this->actingAs($outsider)->getJson("/organizations/{$organizationId}/join-links")->assertStatus(403);
        $this->actingAs($outsider)->postJson("/organizations/{$organizationId}/join-links/{$link->id}/revoke")->assertStatus(403);
    }

    expect($link->fresh()->state)->toBe(LinkStateEnum::active->value);
});

test('an admin revokes an unused link -- it then can\'t be used or switched back on; another organisation\'s link isn\'t theirs to revoke', function () {
    $organization = ojlOrganization();
    $owner = ojlAdmin($organization);
    $coAdmin = ojlAdmin($organization);
    $link = ojlLink($owner, $organization);
    $otherOrganization = ojlOrganization();
    $foreign = ojlLink(ojlAdmin($otherOrganization), $otherOrganization);

    $this->actingAs($coAdmin)->postJson("/organizations/{$organization->id}/join-links/{$link->id}/revoke")
        ->assertOk()->assertJsonPath('link.status', OrganizationJoinLinkResource::REVOKED);
    $this->postJson("/organizations/{$organization->id}/join-links/{$link->id}/revoke")
        ->assertStatus(422)->assertJsonPath('message', LinkException::INACTIVE);
    $this->postJson("/organizations/{$organization->id}/join-links/{$foreign->id}/revoke")->assertStatus(404);

    $this->actingAs($owner)->postJson("/api/links/{$link->id}/status")
        ->assertStatus(422)->assertJsonPath('message', ChangeLinkStatusAction::CANNOT_REACTIVATE);

    expect(ojlRefusal($this, User::factory()->adult()->create(), $link))->toBe(LinkException::INACTIVE)
        ->and($foreign->fresh()->state)->toBe(LinkStateEnum::active->value);
});

test('My organizations lists the person\'s own applications still waiting', function () {
    $organization = ojlOrganization();
    $link = ojlLink(ojlAdmin($organization), $organization);
    $applicant = User::factory()->adult()->create();
    $this->actingAs($applicant)->postJson("/api/links/{$link->uuid}/join")->assertOk();

    $answered = ojlOrganization(['name' => 'Answered Org']);
    Request::unguarded(fn () => Request::create(['type' => RequestTypeEnum::organizationMemberApplication->value, 'status' => RequestStatusEnum::accepted->value,
        'for_type' => Organization::class, 'for_id' => $answered->id, 'from_type' => User::class, 'from_id' => $applicant->id,
        'to_type' => Organization::class, 'to_id' => $answered->id, 'data' => []]));

    $this->get(route('organizations.mine.dashboard'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Organization/MyOrganizations')
            ->has('pendingApplications.data', 1)
            ->where('pendingApplications.data.0.organization.name', 'Harbour Wellbeing'));

    // Someone else's aren't theirs.
    $this->actingAs(User::factory()->adult()->create())->getJson(route('organizations.mine.applications'))
        ->assertOk()->assertJsonCount(0, 'data');
});

test('signing up after opening a link offers it again on the preferences page -- only ever one of our link pages', function () {
    $organization = ojlOrganization();
    $link = ojlLink(ojlAdmin($organization), $organization);

    $this->get("/links/{$link->uuid}")->assertRedirect(route('login'));

    $this->post('/register', ['username' => 'harbour_newcomer', 'password' => 'a-Long-passw0rd!', 'password_confirmation' => 'a-Long-passw0rd!'])
        ->assertRedirect('/preferences');

    $this->get('/preferences')->assertOk()
        ->assertInertia(fn ($page) => $page->where('continueUrl', "/links/{$link->uuid}"));

    // Opening it uses up the offer.
    $this->get("/links/{$link->uuid}")->assertOk();
    $this->get('/preferences')->assertInertia(fn ($page) => $page->where('continueUrl', null));

    // Anything else that was intended is never offered.
    foreach (['https://evil.example/links/'.$link->uuid, '/organizations/mine/dashboard', '/links/not-a-uuid'] as $intended) {
        session()->put('url.intended', $intended);
        $this->get('/preferences')->assertInertia(fn ($page) => $page->where('continueUrl', null));
    }
});
