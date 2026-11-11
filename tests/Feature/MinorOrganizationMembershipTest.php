<?php

use App\Enums\LinkStateEnum;
use App\Enums\LinkTypeEnum;
use App\Enums\OrganizationAdminRoleEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Exceptions\GuardianRequiredException;
use App\Models\Guardianship;
use App\Models\Link;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Request;
use App\Models\User;
use App\Notifications\OrganizationMemberJoinedGuardianNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

// SCRUM-416 (the user's decision: option B, the group-join rule): a minor waiting for a guardian can't join
// an organisation -- not by applying, a join link, accepting an invitation, or an admin accepting their
// application -- and a minor's guardians are told when they do join. Helpers are file-local and
// mom-prefixed (--parallel).

function momOrganization(): array
{
    $organization = Organization::factory()->create(['is_provider' => false, 'is_consumer' => true, 'verified_at' => now(), 'self_apply_enabled' => true, 'name' => 'Harbour Wellbeing']);
    $admin = User::factory()->adult()->create();
    $organization->admins()->attach($admin->id, ['role' => OrganizationAdminRoleEnum::owner->value]);

    return [$organization, $admin];
}

function momMinor(bool $withGuardian): array
{
    $minor = User::factory()->create(['dob' => now()->subYears(15)->toDateString()]);
    $guardian = null;

    if ($withGuardian) {
        $guardian = User::factory()->adult()->create();
        Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minor->id, 'ward_was_minor_at_creation' => true]);
    }

    return [$minor, $guardian];
}

function momRequest(string $type, Organization $organization, User $member): Request
{
    $application = $type === RequestTypeEnum::organizationMemberApplication->value;

    return Request::unguarded(fn () => Request::create([
        'type' => $type, 'status' => RequestStatusEnum::pending->value, 'data' => [],
        'for_type' => Organization::class, 'for_id' => $organization->id,
        'from_type' => $application ? User::class : Organization::class, 'from_id' => $application ? $member->id : $organization->id,
        'to_type' => $application ? Organization::class : User::class, 'to_id' => $application ? $organization->id : $member->id,
    ]));
}

function momIsMember(Organization $organization, User $user): bool
{
    return OrganizationMember::query()->where('organization_id', $organization->id)->where('user_id', $user->id)->exists();
}

test('a minor without a guardian can\'t apply, directly or through a join link -- told it\'s about their guardian', function () {
    [$organization, $admin] = momOrganization();
    [$minor] = momMinor(withGuardian: false);
    $link = Link::unguarded(fn () => Link::create([
        'uuid' => (string) Str::uuid(), 'type' => LinkTypeEnum::organizationSelfApply->value, 'state' => LinkStateEnum::active->value,
        'addedby_type' => User::class, 'addedby_id' => $admin->id, 'for_type' => Organization::class, 'for_id' => $organization->id,
    ]));

    $this->actingAs($minor)->postJson("/organizations/{$organization->id}/member-applications")
        ->assertStatus(403)->assertJsonPath('message', GuardianRequiredException::ORGANIZATION_PAUSED)->assertJsonPath('code', GuardianRequiredException::CODE);

    $this->get("/links/{$link->uuid}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('refusal', GuardianRequiredException::ORGANIZATION_PAUSED)->missing('organization'));
    $this->postJson("/api/links/{$link->uuid}/join")->assertStatus(403);

    expect(Request::query()->whereFor($organization)->exists())->toBeFalse()
        ->and($link->fresh()->state)->toBe(LinkStateEnum::active->value);
});

test('a minor who lost their guardian since applying isn\'t let in by an admin -- the admin is told neutrally', function () {
    [$organization, $admin] = momOrganization();
    [$minor] = momMinor(withGuardian: false);
    $application = momRequest(RequestTypeEnum::organizationMemberApplication->value, $organization, $minor);

    $this->actingAs($admin)->postJson(route('requests.respond', ['requestId' => $application->id]), ['response' => 'accepted'])
        ->assertStatus(403)->assertJsonPath('error', GuardianRequiredException::REQUEST_UNAVAILABLE)->assertJsonMissingPath('code');

    expect(momIsMember($organization, $minor))->toBeFalse()
        ->and($application->fresh()->status)->toBe(RequestStatusEnum::pending->value);
});

test('a minor without a guardian can\'t accept an organisation\'s invitation', function () {
    [$organization] = momOrganization();
    [$minor] = momMinor(withGuardian: false);
    $invite = momRequest(RequestTypeEnum::organizationMemberInvite->value, $organization, $minor);

    $this->actingAs($minor)->postJson(route('requests.respond', ['requestId' => $invite->id]), ['response' => 'accepted'])
        ->assertStatus(403)->assertJsonPath('error', GuardianRequiredException::ORGANIZATION_PAUSED);

    expect(momIsMember($organization, $minor))->toBeFalse();
});

test('a minor with a guardian joins, and the guardian is told -- by an accepted application or an accepted invitation', function () {
    Notification::fake();
    [$organization, $admin] = momOrganization();

    [$applicant, $applicantsGuardian] = momMinor(withGuardian: true);
    $this->actingAs($applicant)->postJson("/organizations/{$organization->id}/member-applications")->assertOk();
    $application = Request::query()->whereFor($organization)->whereFrom($applicant)->firstOrFail();
    $this->actingAs($admin)->postJson(route('requests.respond', ['requestId' => $application->id]), ['response' => 'accepted'])->assertSuccessful();

    [$invitee, $inviteesGuardian] = momMinor(withGuardian: true);
    $invite = momRequest(RequestTypeEnum::organizationMemberInvite->value, $organization, $invitee);
    $this->actingAs($invitee)->postJson(route('requests.respond', ['requestId' => $invite->id]), ['response' => 'accepted'])->assertSuccessful();

    expect(momIsMember($organization, $applicant))->toBeTrue()->and(momIsMember($organization, $invitee))->toBeTrue();
    Notification::assertSentTo($applicantsGuardian, OrganizationMemberJoinedGuardianNotification::class,
        fn ($notification) => $notification->toArray($applicantsGuardian)['organization_name'] === 'Harbour Wellbeing');
    Notification::assertSentTo($inviteesGuardian, OrganizationMemberJoinedGuardianNotification::class);
});

test('an adult joining tells nobody', function () {
    Notification::fake();
    [$organization, $admin] = momOrganization();
    $adult = User::factory()->adult()->create();
    $application = momRequest(RequestTypeEnum::organizationMemberApplication->value, $organization, $adult);

    $this->actingAs($admin)->postJson(route('requests.respond', ['requestId' => $application->id]), ['response' => 'accepted'])->assertSuccessful();

    expect(momIsMember($organization, $adult))->toBeTrue();
    Notification::assertNotSentTo([$adult, $admin], OrganizationMemberJoinedGuardianNotification::class);
    Notification::assertSentTimes(OrganizationMemberJoinedGuardianNotification::class, 0);
});

test('an organisation not taking members says only that, even to a minor without a guardian (SCRUM-179 first)', function () {
    [$organization] = momOrganization();
    $organization->update(['self_apply_enabled' => false]);
    [$minor] = momMinor(withGuardian: false);

    $this->actingAs($minor)->postJson("/organizations/{$organization->id}/member-applications")
        ->assertStatus(422)->assertJsonPath('message', 'You cannot apply to this organization.');
});

test('an admin can still invite a minor without a guardian -- refusing there would tell them who is one; accepting is what\'s refused', function () {
    [$organization, $admin] = momOrganization();
    [$minor] = momMinor(withGuardian: false);

    $this->actingAs($admin)->postJson("/organizations/{$organization->id}/member-invites", ['userId' => $minor->id])->assertOk();

    expect(Request::query()->whereFor($organization)->where('type', RequestTypeEnum::organizationMemberInvite->value)->whereTo($minor)->wherePending()->exists())->toBeTrue()
        ->and(momIsMember($organization, $minor))->toBeFalse();
});
