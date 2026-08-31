<?php

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

        $this->actingAs(User::factory()->create());

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
