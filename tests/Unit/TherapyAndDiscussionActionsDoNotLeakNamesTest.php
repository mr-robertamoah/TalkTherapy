<?php

use App\Actions\Discussion\EnsureCanDeleteDiscussionAction;
use App\Actions\Discussion\EnsureCanEndDiscussionAction;
use App\Actions\Discussion\EnsureCanFailDiscussionAction;
use App\Actions\Discussion\EnsureCanRemoveCounsellorFromDiscussionAction;
use App\Actions\Discussion\EnsureCanUpdateDiscussionAction;
use App\Actions\Discussion\EnsureCanUpdateDiscussionStatusAction;
use App\Actions\Discussion\EnsureDiscussionExistsAction;
use App\Actions\Therapy\EnsureCanUpdateTherapyAction;
use App\Actions\Therapy\EnsureTherapyExistsAction;
use App\DTOs\CreateDiscussionDTO;
use App\DTOs\CreateTherapyDTO;
use App\Exceptions\DiscussionException;
use App\Exceptions\TherapyException;
use App\Exceptions\TherapyNotFoundException;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\Therapy;
use App\Models\User;

// SCRUM-343: the SCRUM-339 rule for therapies and discussions -- an outsider's refusal never names
// the record, and "not found" looks the same as "not allowed"; the owner still gets specifics.

function aNamedDiscussionForLeakTest(array $overrides = []): array
{
    $owner = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()->create()->id]);
    $discussion = Discussion::factory()->create(array_merge([
        'name' => 'Case conference about a suicidal client',
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
        'addedby_type' => Counsellor::class,
        'addedby_id' => $owner->id,
        'start_time' => now()->subMinutes(10),
        'end_time' => now()->addHour(),
        'status' => 'IN_SESSION',
    ], $overrides));

    return [$discussion, $owner];
}

function discussionOrTherapyMessageForLeakTest(callable $run): string
{
    try {
        $run();
    } catch (DiscussionException|TherapyException $e) {
        return $e->getMessage();
    }

    return '';
}

dataset('discussion actions that refuse outsiders', [
    'update status' => [fn ($dto) => EnsureCanUpdateDiscussionStatusAction::new()->execute($dto)],
    'remove counsellor' => [fn ($dto) => EnsureCanRemoveCounsellorFromDiscussionAction::new()->execute($dto)],
    'update' => [fn ($dto) => EnsureCanUpdateDiscussionAction::new()->execute($dto)],
    'delete' => [fn ($dto) => EnsureCanDeleteDiscussionAction::new()->execute($dto)],
    'end' => [fn ($dto) => EnsureCanEndDiscussionAction::new()->execute($dto)],
    'fail' => [fn ($dto) => EnsureCanFailDiscussionAction::new()->execute($dto)],
]);

test('an outsider is refused without the discussion being named', function (Closure $action) {
    [$discussion] = aNamedDiscussionForLeakTest();
    $outsider = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $dto = CreateDiscussionDTO::new()->fromArray(['user' => $outsider->user, 'discussion' => $discussion]);

    $message = discussionOrTherapyMessageForLeakTest(fn () => $action($dto));

    expect($message)->toBe('You are not allowed to do that with this discussion.')
        ->and($message)->not->toContain('suicidal');
})->with('discussion actions that refuse outsiders');

test('a missing discussion gets the same answer as one the caller may not use', function () {
    $dto = CreateDiscussionDTO::new()->fromArray(['user' => User::factory()->create(), 'discussion' => null]);

    expect(discussionOrTherapyMessageForLeakTest(fn () => EnsureDiscussionExistsAction::new()->execute($dto)))
        ->toBe('You are not allowed to do that with this discussion.');
});

test('the discussion\'s owner still gets the specific state messages', function () {
    [$discussion, $owner] = aNamedDiscussionForLeakTest();
    $dto = CreateDiscussionDTO::new()->fromArray(['user' => $owner->user, 'discussion' => $discussion]);

    expect(discussionOrTherapyMessageForLeakTest(fn () => EnsureCanUpdateDiscussionAction::new()->execute($dto)))->toContain('cannot be updated')
        ->and(discussionOrTherapyMessageForLeakTest(fn () => EnsureCanDeleteDiscussionAction::new()->execute($dto)))->toContain('cannot be deleted')
        ->and(discussionOrTherapyMessageForLeakTest(fn () => EnsureCanEndDiscussionAction::new()->execute($dto)))->toBe('You can only end this discussion once its end time has passed.');
});

test('the owner can mark a discussion failed only before its end time', function () {
    [$discussion, $owner] = aNamedDiscussionForLeakTest(['end_time' => now()->subMinute()]);
    $dto = CreateDiscussionDTO::new()->fromArray(['user' => $owner->user, 'discussion' => $discussion]);

    expect(discussionOrTherapyMessageForLeakTest(fn () => EnsureCanFailDiscussionAction::new()->execute($dto)))
        ->toBe('You can only mark this discussion as failed before its end time.');
});

test('an outsider is refused a therapy update without the therapy being named', function () {
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory()->create()->id,
        'name' => 'My panic attacks',
    ]);
    $dto = CreateTherapyDTO::new()->fromArray(['user' => User::factory()->create(), 'therapy' => $therapy]);

    $message = discussionOrTherapyMessageForLeakTest(fn () => EnsureCanUpdateTherapyAction::new()->execute($dto));

    expect($message)->toBe('You are not allowed to do that with this therapy.')
        ->and($message)->not->toContain('panic');
});

// The End/Fail refactor split authorization from the time rule; it must stay exactly as permissive
// as before for the people allowed in: admins ignore the time rule, owners are held to it.
test('an admin may end before the end time and fail after it', function () {
    $admin = User::factory()->has(Administrator::factory())->create();
    [$running] = aNamedDiscussionForLeakTest();
    [$over] = aNamedDiscussionForLeakTest(['end_time' => now()->subMinute()]);

    expect(discussionOrTherapyMessageForLeakTest(fn () => EnsureCanEndDiscussionAction::new()->execute(CreateDiscussionDTO::new()->fromArray(['user' => $admin, 'discussion' => $running]))))->toBe('')
        ->and(discussionOrTherapyMessageForLeakTest(fn () => EnsureCanFailDiscussionAction::new()->execute(CreateDiscussionDTO::new()->fromArray(['user' => $admin, 'discussion' => $over]))))->toBe('');
});

test('an owner may end after the end time and fail before it', function () {
    [$over, $owner] = aNamedDiscussionForLeakTest(['end_time' => now()->subMinute()]);
    $running = Discussion::factory()->create([
        'for_id' => $over->for_id, 'for_type' => Therapy::class,
        'addedby_type' => Counsellor::class, 'addedby_id' => $owner->id,
        'start_time' => now()->subMinutes(10), 'end_time' => now()->addHour(),
    ]);

    expect(discussionOrTherapyMessageForLeakTest(fn () => EnsureCanEndDiscussionAction::new()->execute(CreateDiscussionDTO::new()->fromArray(['user' => $owner->user, 'discussion' => $over]))))->toBe('')
        ->and(discussionOrTherapyMessageForLeakTest(fn () => EnsureCanFailDiscussionAction::new()->execute(CreateDiscussionDTO::new()->fromArray(['user' => $owner->user, 'discussion' => $running]))))->toBe('');
});

test('a missing therapy gets the same answer as one the caller may not use', function () {
    $dto = CreateTherapyDTO::new()->fromArray(['user' => User::factory()->create(), 'therapy' => null]);

    expect(fn () => EnsureTherapyExistsAction::new()->execute($dto))
        ->toThrow(TherapyNotFoundException::class, 'You are not allowed to do that with this therapy.');
});
