<?php

use App\Actions\Link\CreateLinkAction;
use App\Actions\Link\EnsureUserCanCreateOrganizationSelfApplyLinkAction;
use App\Actions\Link\PerformOrganizationSelfApplyLinkAction;
use App\DTOs\CreateLinkDTO;
use App\DTOs\GetLinksDTO;
use App\Enums\LinkStateEnum;
use App\Enums\LinkTypeEnum;
use App\Enums\OrganizationAdminRoleEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Exceptions\LinkException;
use App\Exceptions\OrganizationException;
use App\Models\Link;
use App\Models\Organization;
use App\Models\Request;
use App\Models\User;
use App\Services\LinkService;

function organizationForSelfApplyLink(bool $selfApplyEnabled = true): array
{
    $organization = Organization::factory()->create([
        'is_provider' => false,
        'is_consumer' => true,
        'verified_at' => now(),
        'self_apply_enabled' => $selfApplyEnabled,
    ]);
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);

    return [$organization, $owner];
}

test('an org admin can generate a self-apply link for their organization', function () {
    [$organization, $owner] = organizationForSelfApplyLink();

    EnsureUserCanCreateOrganizationSelfApplyLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray([
            'user' => $owner,
            'type' => LinkTypeEnum::organizationSelfApply->value,
            'for' => $organization,
        ])
    );
})->throwsNoExceptions();

test('a user with no admin relationship to the organization cannot generate a self-apply link for it', function () {
    [$organization] = organizationForSelfApplyLink();
    $outsider = User::factory()->create();

    expect(fn () => EnsureUserCanCreateOrganizationSelfApplyLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray([
            'user' => $outsider,
            'type' => LinkTypeEnum::organizationSelfApply->value,
            'for' => $organization,
        ])
    ))->toThrow(LinkException::class);
});

// Security review (SCRUM-164): a nonexistent organization and an existing one the caller doesn't
// administer must be indistinguishable -- both hit this same guard's generic 403, since it now
// runs before EnsureLinkDataIsValidAction's "for is missing" 422 ever would.
test('generating a self-apply link for a nonexistent organization gets the same rejection as an unadministered one', function () {
    $outsider = User::factory()->create();

    expect(fn () => EnsureUserCanCreateOrganizationSelfApplyLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray([
            'user' => $outsider,
            'type' => LinkTypeEnum::organizationSelfApply->value,
            'for' => null,
        ])
    ))->toThrow(LinkException::class, 'You are not authorized to generate a self-apply link for this organization.');
});

test('the organization self-apply authorization guard is a no-op for every other link type', function () {
    [$organization] = organizationForSelfApplyLink();
    $outsider = User::factory()->create();

    EnsureUserCanCreateOrganizationSelfApplyLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray([
            'user' => $outsider,
            'type' => LinkTypeEnum::guardianship->value,
            'for' => $organization,
        ])
    );
})->throwsNoExceptions();

test('using a self-apply link creates a pending member application for the specific organization it was generated for', function () {
    [$organization, $owner] = organizationForSelfApplyLink();
    $applicant = User::factory()->create();

    $link = CreateLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray([
            'addedby' => $owner,
            'for' => $organization,
            'type' => LinkTypeEnum::organizationSelfApply->value,
        ])
    );

    PerformOrganizationSelfApplyLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray(['user' => $applicant, 'link' => $link])
    );

    $request = Request::query()->whereFor($organization)->first();
    expect($request->type)->toBe(RequestTypeEnum::organizationMemberApplication->value);
    expect($request->status)->toBe(RequestStatusEnum::pending->value);
    expect($request->from_id)->toBe($applicant->id);
});

test('using a self-apply link deactivates it so it cannot be replayed (SCRUM-101 convention)', function () {
    [$organization, $owner] = organizationForSelfApplyLink();
    $applicant = User::factory()->create();

    $link = CreateLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray([
            'addedby' => $owner,
            'for' => $organization,
            'type' => LinkTypeEnum::organizationSelfApply->value,
        ])
    );

    PerformOrganizationSelfApplyLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray(['user' => $applicant, 'link' => $link])
    );

    expect($link->fresh()->state)->toBe(LinkStateEnum::inactive->value);
});

test('using an already-used self-apply link throws instead of applying twice', function () {
    [$organization, $owner] = organizationForSelfApplyLink();
    $firstApplicant = User::factory()->create();
    $secondApplicant = User::factory()->create();

    $link = CreateLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray([
            'addedby' => $owner,
            'for' => $organization,
            'type' => LinkTypeEnum::organizationSelfApply->value,
        ])
    );

    PerformOrganizationSelfApplyLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray(['user' => $firstApplicant, 'link' => $link])
    );

    expect(fn () => PerformOrganizationSelfApplyLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray(['user' => $secondApplicant, 'link' => $link])
    ))->toThrow(LinkException::class, 'This link is no longer active.');

    expect(Request::query()->whereFor($organization)->count())->toBe(1);
});

// SCRUM-164 AC2: same self-apply eligibility checks as TT-6.3a's existing self-apply path --
// a self_apply_enabled=false organization must reject via the link too, not just the direct POST.
test('using a self-apply link for an organization that has self-apply disabled is rejected', function () {
    [$organization, $owner] = organizationForSelfApplyLink(selfApplyEnabled: false);
    $applicant = User::factory()->create();

    $link = CreateLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray([
            'addedby' => $owner,
            'for' => $organization,
            'type' => LinkTypeEnum::organizationSelfApply->value,
        ])
    );

    expect(fn () => PerformOrganizationSelfApplyLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray(['user' => $applicant, 'link' => $link])
    ))->toThrow(OrganizationException::class);

    expect($link->fresh()->state)->toBe(LinkStateEnum::active->value);
});

// Security review (SCRUM-164): the guard must actually stop the real service call, not just the
// isolated Action -- a nonexistent organization gets the same rejection as an unadministered one
// via the real LinkService::createLink() entry point, closing the 422-vs-403 existence oracle.
test('LinkService::createLink rejects a self-apply link for a nonexistent organization the same way as an unadministered one', function () {
    $outsider = User::factory()->create();

    expect(fn () => LinkService::new()->createLink(
        CreateLinkDTO::new()->fromArray([
            'user' => $outsider,
            'addedby' => $outsider,
            'type' => LinkTypeEnum::organizationSelfApply->value,
            'for' => null,
        ])
    ))->toThrow(LinkException::class, 'You are not authorized to generate a self-apply link for this organization.');
});

// Security review (SCRUM-164): createMultipleLinks() builds its own per-item DTO and previously
// skipped this guard entirely -- without it, any authenticated user could bulk-create a working
// self-apply link for an organization they don't administer.
test('LinkService::createMultipleLinks rejects a self-apply link for an organization the caller does not administer', function () {
    [$organization] = organizationForSelfApplyLink();
    $outsider = User::factory()->create();

    expect(fn () => LinkService::new()->createMultipleLinks(
        CreateLinkDTO::new()->fromArray([
            'user' => $outsider,
            'addedby' => $outsider,
            'type' => LinkTypeEnum::organizationSelfApply->value,
            'linksData' => [
                ['forType' => 'Organization', 'forId' => $organization->id, 'toType' => null, 'toId' => null],
            ],
        ])
    ))->toThrow(LinkException::class);

    expect(Link::where('for_id', $organization->id)->where('for_type', Organization::class)->exists())->toBeFalse();
});

// Reviewer finding (SCRUM-164): GetLinksDTO::$for must accept an Organization too, since
// LinkController::getLinks() is the natural way an org admin looks up a self-apply link they've
// already generated (the same generic endpoint every other link type already uses for this).
test('getLinks can be filtered by an Organization for without throwing', function () {
    [$organization, $owner] = organizationForSelfApplyLink();

    $link = CreateLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray([
            'addedby' => $owner,
            'for' => $organization,
            'type' => LinkTypeEnum::organizationSelfApply->value,
        ])
    );

    $result = LinkService::new()->getLinks(
        GetLinksDTO::new()->fromArray([
            'user' => $owner,
            'addedby' => $owner,
            'for' => $organization,
        ])
    );

    expect($result->collection->pluck('id'))->toContain($link->id);
});
