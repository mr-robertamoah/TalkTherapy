<?php

use App\Actions\Organization\WithdrawOrganizationMemberApplicationAction;
use App\Actions\Request\EnsureRequestIsStillPendingAction;
use App\Actions\Request\RespondToOrganizationMemberRequestAction;
use App\DTOs\RequestResponseDTO;
use App\Enums\OrganizationAdminRoleEnum;
use App\Enums\RequestStatusEnum;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Request;
use App\Models\User;

// SCRUM-417: an applicant withdraws their own pending application to join an organisation -- it leaves the
// admins' queue, an admin answering it is told it was withdrawn, and they can apply again. Helpers are
// file-local and woa-prefixed (--parallel).

function woaOrganization(): array
{
    $organization = Organization::factory()->create(['is_provider' => false, 'is_consumer' => true, 'verified_at' => now(), 'self_apply_enabled' => true]);
    $admin = User::factory()->adult()->create();
    $organization->admins()->attach($admin->id, ['role' => OrganizationAdminRoleEnum::owner->value]);

    return [$organization, $admin];
}

function woaApply($test, User $applicant, Organization $organization): Request
{
    $test->actingAs($applicant)->postJson("/organizations/{$organization->id}/member-applications")->assertOk();

    return Request::query()->whereFor($organization)->whereFrom($applicant)->latest('id')->firstOrFail();
}

function woaWithdraw($test, User $user, Request $application)
{
    return $test->actingAs($user)->postJson(route('organizations.members.applications.withdraw', ['requestId' => $application->id]));
}

test('the applicant withdraws their pending application: it leaves their list and the admins\' queue', function () {
    [$organization, $admin] = woaOrganization();
    $applicant = User::factory()->adult()->create();
    $application = woaApply($this, $applicant, $organization);

    woaWithdraw($this, $applicant, $application)->assertOk();

    $application->refresh();
    expect($application->status)->toBe(RequestStatusEnum::inconsequencial->value)
        ->and($application->wasWithdrawn())->toBeTrue()
        ->and($application->data['reason'])->toBe(WithdrawOrganizationMemberApplicationAction::WITHDRAWN)
        ->and($application->responded_at)->not->toBeNull();

    $this->actingAs($applicant)->getJson(route('organizations.mine.applications'))->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($admin)->getJson("/organizations/{$organization->id}/requests")->assertOk()->assertJsonCount(0, 'data');
});

test('someone else\'s application, or none, is the same not-found; one already answered is a conflict', function () {
    [$organization, $admin] = woaOrganization();
    $applicant = User::factory()->adult()->create();
    $application = woaApply($this, $applicant, $organization);

    woaWithdraw($this, User::factory()->adult()->create(), $application)
        ->assertStatus(404)->assertJsonPath('message', WithdrawOrganizationMemberApplicationAction::NOT_FOUND);
    $this->actingAs($applicant)->postJson(route('organizations.members.applications.withdraw', ['requestId' => $application->id + 1000]))
        ->assertStatus(404)->assertJsonPath('message', WithdrawOrganizationMemberApplicationAction::NOT_FOUND);

    $this->actingAs($admin)->postJson(route('requests.respond', ['requestId' => $application->id]), ['response' => 'accepted'])->assertSuccessful();

    woaWithdraw($this, $applicant, $application)
        ->assertStatus(409)->assertJsonPath('message', WithdrawOrganizationMemberApplicationAction::NOT_PENDING);
    expect(OrganizationMember::query()->where('organization_id', $organization->id)->where('user_id', $applicant->id)->exists())->toBeTrue();
});

test('an admin answering a withdrawn application is told so -- also when it was withdrawn while they answered', function () {
    [$organization, $admin] = woaOrganization();
    $applicant = User::factory()->adult()->create();
    $application = woaApply($this, $applicant, $organization);
    woaWithdraw($this, $applicant, $application)->assertOk();

    $this->actingAs($admin)->postJson(route('requests.respond', ['requestId' => $application->id]), ['response' => 'accepted'])
        ->assertStatus(422)->assertJsonPath('error', EnsureRequestIsStillPendingAction::WITHDRAWN);

    // The race: past the unlocked pre-check with a stale copy, then the locked responder finds it withdrawn.
    $stale = $application->replicate();
    $stale->id = $application->id;
    $stale->status = RequestStatusEnum::pending->value;
    expect(fn () => RespondToOrganizationMemberRequestAction::new()->execute(
        RequestResponseDTO::new()->fromArray(['user' => $admin, 'request' => $stale, 'response' => 'accepted'])
    ))->toThrow(EnsureRequestIsStillPendingAction::WITHDRAWN);

    expect(OrganizationMember::query()->where('organization_id', $organization->id)->where('user_id', $applicant->id)->exists())->toBeFalse();
});

test('after withdrawing they can apply again', function () {
    [$organization] = woaOrganization();
    $applicant = User::factory()->adult()->create();
    woaWithdraw($this, $applicant, woaApply($this, $applicant, $organization))->assertOk();

    woaApply($this, $applicant, $organization);

    expect(Request::query()->whereFor($organization)->whereFrom($applicant)->wherePending()->count())->toBe(1);
});

test('withdrawing is throttled', function () {
    [$organization] = woaOrganization();
    $applicant = User::factory()->adult()->create();
    $application = woaApply($this, $applicant, $organization);

    // The first withdraws; the next nine are 409s (already withdrawn) -- all count towards the limit.
    foreach (range(1, 10) as $ignored) {
        woaWithdraw($this, $applicant, $application);
    }

    woaWithdraw($this, $applicant, $application)->assertStatus(429);
});
