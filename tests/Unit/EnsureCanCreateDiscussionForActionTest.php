<?php

use App\Actions\Discussion\EnsureCanCreateDiscussionForAction;
use App\DTOs\CreateDiscussionDTO;
use App\Enums\CounsellorGroupTherapyRoleEnum;
use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Exceptions\DiscussionException;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Therapy;
use App\Models\User;
use App\Services\DiscussionService;
use Illuminate\Support\Facades\Notification;

// SCRUM-104: DiscussionService::createDiscussion resolved `for` (the Therapy/GroupTherapy) from
// client-supplied input with no check that the acting counsellor actually has a relationship to
// it -- any counsellor could create a discussion for someone else's therapy, becoming its
// legitimate `addedby`, which then trivially satisfied SCRUM-102's discussion-invite
// authorization check (which trusts a discussion's own addedby/participants).
//
// Named `createDiscussionCounsellor()` (not `aCounsellor()`/`aDiscussionCounsellor()`) to stay
// unique across the whole tests/ tree -- this project's CI runs `php artisan test --parallel`,
// which splits test files across worker processes, so a global helper is only safe to rely on
// when it's defined in the same file that uses it.
function createDiscussionCounsellor(): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
}

test('the assigned counsellor of a therapy can create a discussion for it', function () {
    $therapyOwner = User::factory()->create();
    $counsellor = createDiscussionCounsellor();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $therapyOwner->id,
        'counsellor_id' => $counsellor->id,
    ]);

    expect(fn () => EnsureCanCreateDiscussionForAction::new()->execute(
        CreateDiscussionDTO::new()->fromArray(['addedby' => $counsellor, 'for' => $therapy])
    ))->not->toThrow(DiscussionException::class);
});

test('a counsellor with no relationship to the therapy cannot create a discussion for it', function () {
    $therapyOwner = User::factory()->create();
    $assignedCounsellor = createDiscussionCounsellor();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $therapyOwner->id,
        'counsellor_id' => $assignedCounsellor->id,
    ]);
    $outsider = createDiscussionCounsellor();

    expect(fn () => EnsureCanCreateDiscussionForAction::new()->execute(
        CreateDiscussionDTO::new()->fromArray(['addedby' => $outsider, 'for' => $therapy])
    ))->toThrow(DiscussionException::class, 'You are not authorized to create a discussion for this therapy or group therapy.');
});

test('the addedby counsellor of a group therapy can create a discussion for it', function () {
    $groupOwner = createDiscussionCounsellor();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => Counsellor::class,
        'addedby_id' => $groupOwner->id,
    ]);

    expect(fn () => EnsureCanCreateDiscussionForAction::new()->execute(
        CreateDiscussionDTO::new()->fromArray(['addedby' => $groupOwner, 'for' => $groupTherapy])
    ))->not->toThrow(DiscussionException::class);
});

test('an attached participant counsellor of a group therapy can create a discussion for it', function () {
    $groupOwner = createDiscussionCounsellor();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => Counsellor::class,
        'addedby_id' => $groupOwner->id,
    ]);
    $participant = createDiscussionCounsellor();
    $groupTherapy->counsellors()->attach($participant->id, [
        'state' => CounsellorGroupTherapyStateEnum::active->value,
        'role' => CounsellorGroupTherapyRoleEnum::normal->value,
    ]);

    expect(fn () => EnsureCanCreateDiscussionForAction::new()->execute(
        CreateDiscussionDTO::new()->fromArray(['addedby' => $participant, 'for' => $groupTherapy])
    ))->not->toThrow(DiscussionException::class);
});

test('an inactive (removed) participant counsellor of a group therapy cannot create a discussion for it', function () {
    // GroupTherapy::isCounsellor() -- the exact method this action's authorization relies on
    // for group therapies -- previously ignored the counsellor_group_therapy pivot's `state`
    // column entirely, so a counsellor removed from a group therapy (state=inactive) would
    // still be treated as authorized.
    $groupOwner = createDiscussionCounsellor();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => Counsellor::class,
        'addedby_id' => $groupOwner->id,
    ]);
    $removedParticipant = createDiscussionCounsellor();
    $groupTherapy->counsellors()->attach($removedParticipant->id, [
        'state' => CounsellorGroupTherapyStateEnum::inactive->value,
        'role' => CounsellorGroupTherapyRoleEnum::normal->value,
    ]);

    expect(fn () => EnsureCanCreateDiscussionForAction::new()->execute(
        CreateDiscussionDTO::new()->fromArray(['addedby' => $removedParticipant, 'for' => $groupTherapy])
    ))->toThrow(DiscussionException::class);
});

test('a counsellor with no relationship to the group therapy cannot create a discussion for it', function () {
    $groupOwner = createDiscussionCounsellor();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => Counsellor::class,
        'addedby_id' => $groupOwner->id,
    ]);
    $outsider = createDiscussionCounsellor();

    expect(fn () => EnsureCanCreateDiscussionForAction::new()->execute(
        CreateDiscussionDTO::new()->fromArray(['addedby' => $outsider, 'for' => $groupTherapy])
    ))->toThrow(DiscussionException::class);
});

test('an admin cannot bypass this check by naming an unrelated counsellor as addedby', function () {
    // Unlike its EnsureCan*DiscussionAction siblings (update/delete/end, which act on a
    // discussion that already exists), this check has no isAdmin() bypass: an admin acting
    // through EnsureAddedbyIsValidAction's own bypass could otherwise set `addedby` to any
    // counsellor and fabricate a relationship to a therapy that never actually existed.
    $admin = User::factory()->has(Administrator::factory())->create();
    $therapyOwner = User::factory()->create();
    $assignedCounsellor = createDiscussionCounsellor();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $therapyOwner->id,
        'counsellor_id' => $assignedCounsellor->id,
    ]);
    $unrelatedCounsellor = createDiscussionCounsellor();

    expect(fn () => EnsureCanCreateDiscussionForAction::new()->execute(
        CreateDiscussionDTO::new()->fromArray([
            'user' => $admin,
            'addedby' => $unrelatedCounsellor,
            'for' => $therapy,
        ])
    ))->toThrow(DiscussionException::class);
});

test('a missing `for` is not treated as an authorization failure', function () {
    // Defers to EnsureDiscussionDataIsValidAction's own, clearer "No therapy or group therapy
    // for the discussion was given." message instead of masking it with "not authorized".
    $counsellor = createDiscussionCounsellor();

    expect(fn () => EnsureCanCreateDiscussionForAction::new()->execute(
        CreateDiscussionDTO::new()->fromArray(['addedby' => $counsellor, 'for' => null])
    ))->not->toThrow(DiscussionException::class);
});

test('a non-counsellor `addedby` is cleanly rejected instead of an uncaught TypeError', function () {
    $therapyOwner = User::factory()->create();
    $counsellor = createDiscussionCounsellor();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $therapyOwner->id,
        'counsellor_id' => $counsellor->id,
    ]);

    expect(fn () => EnsureCanCreateDiscussionForAction::new()->execute(
        CreateDiscussionDTO::new()->fromArray(['addedby' => $therapyOwner, 'for' => $therapy])
    ))->toThrow(DiscussionException::class);
});

test('DiscussionService::createDiscussion rejects a counsellor with no relationship to the therapy', function () {
    Notification::fake();

    $therapyOwner = User::factory()->create();
    $assignedCounsellor = createDiscussionCounsellor();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $therapyOwner->id,
        'counsellor_id' => $assignedCounsellor->id,
    ]);
    $outsider = createDiscussionCounsellor();

    expect(fn () => DiscussionService::new()->createDiscussion(
        CreateDiscussionDTO::new()->fromArray([
            'user' => $outsider->user,
            'addedby' => $outsider,
            'for' => $therapy,
            'name' => 'Case review',
            'description' => 'Discussing the case',
            'startTime' => now()->addDay(),
            'endTime' => now()->addDay()->addHour(),
        ])
    ))->toThrow(DiscussionException::class);
});

test('DiscussionService::createDiscussion succeeds for the therapy\'s assigned counsellor', function () {
    Notification::fake();

    $therapyOwner = User::factory()->create();
    $counsellor = createDiscussionCounsellor();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $therapyOwner->id,
        'counsellor_id' => $counsellor->id,
    ]);

    $discussion = DiscussionService::new()->createDiscussion(
        CreateDiscussionDTO::new()->fromArray([
            'user' => $counsellor->user,
            'addedby' => $counsellor,
            'for' => $therapy,
            'name' => 'Case review',
            'description' => 'Discussing the case',
            'startTime' => now()->addDay(),
            'endTime' => now()->addDay()->addHour(),
        ])
    );

    expect($discussion->for->is($therapy))->toBeTrue();
    expect($discussion->addedby->is($counsellor))->toBeTrue();
});
