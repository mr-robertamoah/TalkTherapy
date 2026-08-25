<?php

use App\DTOs\GetDiscussionsDTO;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\Therapy;
use App\Models\User;
use App\Services\DiscussionService;

// SCRUM-103: Counsellor::discussions() declared belongsToMany(Counsellor::class, ...) instead of
// belongsToMany(Discussion::class, ...) -- calling it hydrated rows from the wrong table
// entirely. This is a real, reachable bug, not dead code: Counsellor::scopeWhereDiscussion()
// calls whereHas('discussions', ...), which is used by DiscussionService::getDiscussionCounsellors(),
// which backs the authenticated GET /discussions/{discussionId}/counsellors endpoint -- so the
// "who's participating in this discussion" listing was silently wrong for any real discussion.

test('Counsellor::discussions() returns actual Discussion models via the counsellor_discussion pivot', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $therapyOwner = User::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $therapyOwner->id]);
    $discussion = Discussion::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);

    $counsellor->discussions()->attach($discussion->id);

    $related = $counsellor->discussions;

    expect($related)->toHaveCount(1);
    expect($related->first())->toBeInstanceOf(Discussion::class);
    expect($related->first()->id)->toBe($discussion->id);
});

test('DiscussionService::getDiscussionCounsellors returns only the counsellors actually attached to that discussion', function () {
    // The buggy relation's self-join (`belongsToMany(Counsellor::class, ...)` instead of
    // `Discussion::class`) requires a *counsellor* whose id happens to equal the target
    // discussion's id for whereHas('discussions', ...) to match at all -- so this bug is only
    // reliably caught when the discussion's id is guaranteed higher than any counsellor's id
    // (otherwise a coincidental id match between the two independently-incrementing tables can
    // mask the bug, as it did in an earlier draft of this test).
    $attachedCounsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $unrelatedCounsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);

    $therapyOwner = User::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $therapyOwner->id]);
    Discussion::factory()->count(10)->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);
    $discussion = Discussion::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);
    $otherDiscussion = Discussion::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);

    expect($discussion->id)->toBeGreaterThan($attachedCounsellor->id);
    expect($discussion->id)->toBeGreaterThan($unrelatedCounsellor->id);

    $attachedCounsellor->discussions()->attach($discussion->id);
    $unrelatedCounsellor->discussions()->attach($otherDiscussion->id);

    $result = DiscussionService::new()->getDiscussionCounsellors(
        GetDiscussionsDTO::new()->fromArray(['discussion' => $discussion])
    );

    $ids = collect($result)->pluck('id')->all();

    expect($ids)->toContain($attachedCounsellor->id);
    expect($ids)->not->toContain($unrelatedCounsellor->id);
});
