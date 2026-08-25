<?php

use App\Actions\Discussion\EnsureCanSendDiscussionRequestAction;
use App\DTOs\CreateRequestDTO;
use App\Exceptions\DiscussionException;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\Request as RequestModel;
use App\Models\Therapy;
use App\Models\User;
use App\Services\DiscussionService;
use Illuminate\Support\Facades\Notification;

// SCRUM-102: DiscussionService::sendCounsellorRequest previously had no check that `from` was
// actually allowed to invite counsellors into the discussion -- any authenticated counsellor
// could send a "join this discussion" request regardless of their relationship to it.

function aTherapyOwnedBy(User $owner): Therapy
{
    return Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $owner->id]);
}

// Discussion::addedby is always a Counsellor in practice (the only relation pointing at it is
// Counsellor::addedDiscussions()), and Discussion::isParticipant() is type-hinted ?Counsellor --
// a User there would throw a TypeError, not the DiscussionException these tests care about.
// Defined locally (not reused from a sibling test file's global helper) because `php artisan
// test --parallel` (this project's CI runner) splits test files across separate worker
// processes -- a global function from another file isn't guaranteed to be loaded in this one's
// process.
function aDiscussionCounsellor(): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
}

function aDiscussionOwnedBy(Counsellor $owner, Therapy $therapy): Discussion
{
    return Discussion::factory()->create([
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
        'addedby_id' => $owner->id,
        'addedby_type' => Counsellor::class,
    ]);
}

test('the discussion owner can send a counsellor request', function () {
    $therapyOwner = User::factory()->create();
    $therapy = aTherapyOwnedBy($therapyOwner);
    $discussionOwner = aDiscussionCounsellor();
    $discussion = aDiscussionOwnedBy($discussionOwner, $therapy);

    expect(fn () => EnsureCanSendDiscussionRequestAction::new()->execute(
        CreateRequestDTO::new()->fromArray(['from' => $discussionOwner, 'for' => $discussion])
    ))->not->toThrow(Throwable::class);
});

test('an existing participant counsellor can send a counsellor request', function () {
    $therapyOwner = User::factory()->create();
    $therapy = aTherapyOwnedBy($therapyOwner);
    $discussionOwner = aDiscussionCounsellor();
    $discussion = aDiscussionOwnedBy($discussionOwner, $therapy);
    $participant = aDiscussionCounsellor();
    $discussion->counsellors()->attach($participant->id);

    expect(fn () => EnsureCanSendDiscussionRequestAction::new()->execute(
        CreateRequestDTO::new()->fromArray(['from' => $participant, 'for' => $discussion])
    ))->not->toThrow(Throwable::class);
});

test('a counsellor with no relationship to the discussion cannot send a counsellor request', function () {
    $therapyOwner = User::factory()->create();
    $therapy = aTherapyOwnedBy($therapyOwner);
    $discussionOwner = aDiscussionCounsellor();
    $discussion = aDiscussionOwnedBy($discussionOwner, $therapy);
    $outsider = aDiscussionCounsellor();

    expect(fn () => EnsureCanSendDiscussionRequestAction::new()->execute(
        CreateRequestDTO::new()->fromArray(['from' => $outsider, 'for' => $discussion])
    ))->toThrow(DiscussionException::class, 'You are not authorized to send a request for this discussion.');
});

test('a non-counsellor `from` is cleanly rejected instead of throwing a TypeError', function () {
    // Discussion::isParticipant() is type-hinted ?Counsellor; without an explicit instanceof
    // guard here, a `from` that's ever not a Counsellor (e.g. via a future caller reusing this
    // action with a plain User) would surface as an uncaught TypeError instead of this same
    // clean, existing DiscussionException.
    $therapyOwner = User::factory()->create();
    $therapy = aTherapyOwnedBy($therapyOwner);
    $discussionOwner = aDiscussionCounsellor();
    $discussion = aDiscussionOwnedBy($discussionOwner, $therapy);
    $nonCounsellorUser = User::factory()->create();

    expect(fn () => EnsureCanSendDiscussionRequestAction::new()->execute(
        CreateRequestDTO::new()->fromArray(['from' => $nonCounsellorUser, 'for' => $discussion])
    ))->toThrow(DiscussionException::class, 'You are not authorized to send a request for this discussion.');
});

test('DiscussionService::sendCounsellorRequest rejects a counsellor with no relationship to the discussion', function () {
    Notification::fake();

    $therapyOwner = User::factory()->create();
    $therapy = aTherapyOwnedBy($therapyOwner);
    $discussionOwner = aDiscussionCounsellor();
    $discussion = aDiscussionOwnedBy($discussionOwner, $therapy);
    $outsider = aDiscussionCounsellor();
    $target = aDiscussionCounsellor();

    expect(fn () => DiscussionService::new()->sendCounsellorRequest(
        CreateRequestDTO::new()->fromArray(['from' => $outsider, 'for' => $discussion, 'to' => $target])
    ))->toThrow(DiscussionException::class);

    expect(RequestModel::query()->whereFor($discussion)->count())->toBe(0);
});
