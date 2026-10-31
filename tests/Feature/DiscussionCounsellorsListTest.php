<?php

use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// SCRUM-182/TT-10.2: DiscussionService::getDiscussionCounsellors serializes via
// CounsellorMiniResource, which reads ->avatar. Migrating avatar off the nullable avatar_id
// belongsTo (which skipped the query entirely when null) onto the tagged fileables MorphToMany
// (which always queries unless eager-loaded) turned this endpoint into a real N+1 -- caught by
// reviewer inspection, not an existing test, since this endpoint had zero coverage before.

test('listing discussion counsellors does not N+1 on avatar', function () {
    $queryCountFor = function (int $count) {
        $therapyOwner = User::factory()->create();
        $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $therapyOwner->id]);
        $discussion = Discussion::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);

        foreach (range(1, $count) as $i) {
            $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
            $counsellor->discussions()->attach($discussion->id);
        }

        // An admin (SCRUM-204): anyone outside the discussion now gets an empty list, which would
        // make this query-count comparison meaningless.
        $this->actingAs(User::factory()->has(Administrator::factory())->create());

        DB::enableQueryLog();
        $this->getJson(route('api.discussions.counsellors', ['discussionId' => $discussion->id]))->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        return $queryCount;
    };

    // Fixed overhead (auth, session, the paginated/eager-load queries themselves) is identical
    // regardless of row count -- only a genuine per-row N+1 would make this grow with the
    // attached-counsellor count.
    expect($queryCountFor(10))->toBe($queryCountFor(2));
});

// SCRUM-204: the roster is only for the discussion's own counsellors and admins.
function aDiscussionForRosterVisibility(): array
{
    $therapyOwner = User::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $therapyOwner->id]);
    $owner = Counsellor::factory()->create(['user_id' => User::factory()]);
    $discussion = Discussion::factory()->create([
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
        'addedby_type' => Counsellor::class,
        'addedby_id' => $owner->id,
    ]);
    $member = Counsellor::factory()->create(['user_id' => User::factory()]);
    $member->discussions()->attach($discussion->id);

    return compact('discussion', 'owner', 'member');
}

test('a discussion\'s own counsellors and admins can see its roster', function () {
    $data = aDiscussionForRosterVisibility();
    $admin = User::factory()->has(Administrator::factory())->create();

    foreach ([$data['owner']->user, $data['member']->user, $admin] as $viewer) {
        $this->actingAs($viewer)
            ->getJson(route('api.discussions.counsellors', ['discussionId' => $data['discussion']->id]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $data['member']->id);
    }
});

test('anyone else gets the same empty list as a discussion that does not exist', function () {
    $data = aDiscussionForRosterVisibility();
    $otherCounsellor = Counsellor::factory()->create(['user_id' => User::factory()]);

    foreach ([User::factory()->create(), $otherCounsellor->user] as $viewer) {
        $this->actingAs($viewer)
            ->getJson(route('api.discussions.counsellors', ['discussionId' => $data['discussion']->id]))
            ->assertOk()
            ->assertExactJson([]);
    }

    $this->actingAs($otherCounsellor->user)
        ->getJson(route('api.discussions.counsellors', ['discussionId' => 999999]))
        ->assertOk()
        ->assertExactJson([]);
});

test('a discussion with no recorded creator is refused cleanly, not with an error', function () {
    $therapyOwner = User::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $therapyOwner->id]);
    $discussion = Discussion::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);

    $this->actingAs($counsellor->user)
        ->getJson(route('api.discussions.counsellors', ['discussionId' => $discussion->id]))
        ->assertOk()
        ->assertExactJson([]);
});
