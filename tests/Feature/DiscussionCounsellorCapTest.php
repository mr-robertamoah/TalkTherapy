<?php

use App\DTOs\CreateDiscussionDTO;
use App\Enums\LinkStateEnum;
use App\Enums\LinkTypeEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Exceptions\DiscussionException;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\Link;
use App\Models\Request;
use App\Models\Therapy;
use App\Models\User;
use App\Services\DiscussionService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

// SCRUM-23/TT-2.4: an optional per-discussion cap on the number of counsellors that can join,
// enforced at the two places a counsellor is actually attached to a discussion (accepting a
// discussion request, or using an invite link) -- both must independently enforce it, or the cap
// is trivially bypassable via whichever path was missed.

function aCounsellorForDiscussionCapRoute(): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()]);
}

function aDiscussionForDiscussionCapRoute(Counsellor $creator, ?int $maxCounsellors = null): Discussion
{
    $therapyOwner = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $therapyOwner->id,
        'counsellor_id' => $creator->id,
    ]);

    Notification::fake();

    return DiscussionService::new()->createDiscussion(
        CreateDiscussionDTO::new()->fromArray([
            'user' => $creator->user,
            'addedby' => $creator,
            'for' => $therapy,
            'name' => 'Case review',
            'description' => 'Discussing the case',
            'startTime' => now()->addDay(),
            'endTime' => now()->addDay()->addHour(),
            'maxCounsellors' => $maxCounsellors,
        ])
    );
}

function aPendingDiscussionRequestForDiscussionCapRoute(Discussion $discussion, Counsellor $from, Counsellor $to): Request
{
    return Request::factory()->create([
        'from_type' => Counsellor::class,
        'from_id' => $from->id,
        'to_type' => Counsellor::class,
        'to_id' => $to->id,
        'for_type' => Discussion::class,
        'for_id' => $discussion->id,
        'type' => RequestTypeEnum::discussion->value,
        'status' => RequestStatusEnum::pending->value,
        'data' => [],
    ]);
}

function anActiveDiscussionLinkForDiscussionCapRoute(Discussion $discussion, Counsellor $creator): Link
{
    // Link::$fillable deliberately excludes the addedby/for morph columns (see
    // CreateLinkAction) -- they must be set via the relations, not mass-assigned, or they're
    // silently dropped.
    $link = $creator->addedLinks()->create([
        'state' => LinkStateEnum::active->value,
        'type' => LinkTypeEnum::discussion->value,
        'uuid' => Str::uuid(),
    ]);

    $link->for()->associate($discussion);
    $link->save();

    return $link->refresh();
}

test('creating a discussion with a max counsellors cap persists it', function () {
    $creator = aCounsellorForDiscussionCapRoute();
    $discussion = aDiscussionForDiscussionCapRoute($creator, 3);

    expect($discussion->max_counsellors)->toBe(3);
});

test('a discussion with no cap set is unlimited by default', function () {
    $creator = aCounsellorForDiscussionCapRoute();
    $discussion = aDiscussionForDiscussionCapRoute($creator);

    expect($discussion->max_counsellors)->toBeNull();
});

test('setting the cap to zero or a negative number is rejected', function () {
    $creator = aCounsellorForDiscussionCapRoute();

    expect(fn () => aDiscussionForDiscussionCapRoute($creator, 0))
        ->toThrow(DiscussionException::class);
});

test('the discussion creator can change the cap', function () {
    $creator = aCounsellorForDiscussionCapRoute();
    $discussion = aDiscussionForDiscussionCapRoute($creator, 5);

    Notification::fake();

    $updated = DiscussionService::new()->updateDiscussion(
        CreateDiscussionDTO::new()->fromArray([
            'user' => $creator->user,
            'discussion' => $discussion,
            'addedby' => $discussion->addedby,
            'for' => $discussion->for,
            'startTime' => $discussion->start_time,
            'endTime' => $discussion->end_time,
            'maxCounsellors' => 2,
        ])
    );

    expect($updated->max_counsellors)->toBe(2);
});

test('a counsellor who is neither the creator nor an admin cannot change the cap', function () {
    $creator = aCounsellorForDiscussionCapRoute();
    $discussion = aDiscussionForDiscussionCapRoute($creator, 5);
    $outsider = aCounsellorForDiscussionCapRoute();

    expect(fn () => DiscussionService::new()->updateDiscussion(
        CreateDiscussionDTO::new()->fromArray([
            'user' => $outsider->user,
            'discussion' => $discussion,
            'addedby' => $discussion->addedby,
            'for' => $discussion->for,
            'startTime' => $discussion->start_time,
            'endTime' => $discussion->end_time,
            'maxCounsellors' => 1,
        ])
    ))->toThrow(DiscussionException::class);

    expect($discussion->fresh()->max_counsellors)->toBe(5);
});

test('accepting a discussion request succeeds while under the cap', function () {
    $creator = aCounsellorForDiscussionCapRoute();
    $discussion = aDiscussionForDiscussionCapRoute($creator, 2);
    $joiningCounsellor = aCounsellorForDiscussionCapRoute();
    $request = aPendingDiscussionRequestForDiscussionCapRoute($discussion, $creator, $joiningCounsellor);

    $response = $this
        ->actingAs($joiningCounsellor->user)
        ->post(route('requests.respond', ['requestId' => $request->id]), ['response' => 'accepted']);

    $response->assertStatus(201);
    expect($discussion->counsellors()->where('counsellor_id', $joiningCounsellor->id)->exists())->toBeTrue();
});

test('accepting a discussion request fails once the cap is already reached', function () {
    $creator = aCounsellorForDiscussionCapRoute();
    $discussion = aDiscussionForDiscussionCapRoute($creator, 1);
    $existingCounsellor = aCounsellorForDiscussionCapRoute();
    $discussion->counsellors()->attach($existingCounsellor->id);

    $joiningCounsellor = aCounsellorForDiscussionCapRoute();
    $request = aPendingDiscussionRequestForDiscussionCapRoute($discussion, $creator, $joiningCounsellor);

    $response = $this
        ->actingAs($joiningCounsellor->user)
        ->post(route('requests.respond', ['requestId' => $request->id]), ['response' => 'accepted']);

    $response->assertStatus(422);
    expect($discussion->counsellors()->where('counsellor_id', $joiningCounsellor->id)->exists())->toBeFalse();
    // The whole accept attempt rolls back on the capacity check, including the status update --
    // the request stays pending rather than being silently consumed by a failed accept.
    expect($request->fresh()->status)->toBe(RequestStatusEnum::pending->value);
});

test('a discussion with no cap accepts requests without limit', function () {
    $creator = aCounsellorForDiscussionCapRoute();
    $discussion = aDiscussionForDiscussionCapRoute($creator);
    foreach (range(1, 3) as $i) {
        $discussion->counsellors()->attach(aCounsellorForDiscussionCapRoute()->id);
    }

    $joiningCounsellor = aCounsellorForDiscussionCapRoute();
    $request = aPendingDiscussionRequestForDiscussionCapRoute($discussion, $creator, $joiningCounsellor);

    $this
        ->actingAs($joiningCounsellor->user)
        ->post(route('requests.respond', ['requestId' => $request->id]), ['response' => 'accepted'])
        ->assertStatus(201);
});

test('using an invite link succeeds while under the cap', function () {
    $creator = aCounsellorForDiscussionCapRoute();
    $discussion = aDiscussionForDiscussionCapRoute($creator, 2);
    $link = anActiveDiscussionLinkForDiscussionCapRoute($discussion, $creator);
    $joiningCounsellor = aCounsellorForDiscussionCapRoute();

    $this
        ->actingAs($joiningCounsellor->user)
        ->get(route('links.get', ['uuid' => $link->uuid]));

    expect($discussion->counsellors()->where('counsellor_id', $joiningCounsellor->id)->exists())->toBeTrue();
});

test('using an invite link fails once the cap is already reached', function () {
    $creator = aCounsellorForDiscussionCapRoute();
    $discussion = aDiscussionForDiscussionCapRoute($creator, 1);
    $existingCounsellor = aCounsellorForDiscussionCapRoute();
    $discussion->counsellors()->attach($existingCounsellor->id);
    $link = anActiveDiscussionLinkForDiscussionCapRoute($discussion, $creator);
    $joiningCounsellor = aCounsellorForDiscussionCapRoute();

    $this
        ->actingAs($joiningCounsellor->user)
        ->get(route('links.get', ['uuid' => $link->uuid]));

    expect($discussion->counsellors()->where('counsellor_id', $joiningCounsellor->id)->exists())->toBeFalse();
    // The link must not be silently consumed by a failed, over-cap join attempt -- it should
    // remain usable by someone else once the cap allows it (e.g. after being raised).
    expect($link->fresh()->state)->toBe(LinkStateEnum::active->value);
});

test('lowering the cap below the current participant count does not evict existing counsellors', function () {
    $creator = aCounsellorForDiscussionCapRoute();
    $discussion = aDiscussionForDiscussionCapRoute($creator, 5);
    foreach (range(1, 3) as $i) {
        $discussion->counsellors()->attach(aCounsellorForDiscussionCapRoute()->id);
    }

    Notification::fake();
    DiscussionService::new()->updateDiscussion(
        CreateDiscussionDTO::new()->fromArray([
            'user' => $creator->user,
            'discussion' => $discussion,
            'addedby' => $discussion->addedby,
            'for' => $discussion->for,
            'startTime' => $discussion->start_time,
            'endTime' => $discussion->end_time,
            'maxCounsellors' => 1,
        ])
    );

    expect($discussion->counsellors()->count())->toBe(3);

    // Future additions are still blocked, though -- the cap only stops growing past it.
    $joiningCounsellor = aCounsellorForDiscussionCapRoute();
    $request = aPendingDiscussionRequestForDiscussionCapRoute($discussion, $creator, $joiningCounsellor);

    $this
        ->actingAs($joiningCounsellor->user)
        ->post(route('requests.respond', ['requestId' => $request->id]), ['response' => 'accepted'])
        ->assertStatus(422);
});
