<?php

namespace App\Actions\Post;

use App\Actions\Action;
use App\Actions\Star\AwardCounsellorStarAction;
use App\Enums\StarSourceEnum;
use App\Models\Counsellor;
use App\Models\Post;
use App\Models\Star;
use Illuminate\Support\Facades\DB;

// TT-7.11b/SCRUM-471: a post published as a counsellor earns that counsellor a star, up to
// config('recognition.posts_per_day') starred posts a UTC day. The counsellor's row is locked while the day's
// post stars are counted and the new one written, so two posts at the same moment can't both slip under the cap.
// A post made as a user (not a counsellor) earns nothing. Returns the star, or null when none was given.
class AwardPostPublishedStarAction extends Action
{
    public function execute(Post $post): ?Star
    {
        $counsellor = $post->addedby;

        if (! $counsellor instanceof Counsellor) {
            return null;
        }

        return DB::transaction(function () use ($counsellor, $post) {
            Counsellor::query()->whereKey($counsellor->id)->lockForUpdate()->first();

            // Today in UTC, expressed in the app's timezone (what created_at is stored in).
            $from = now()->utc()->startOfDay()->setTimezone(config('app.timezone'));

            $starredToday = Star::query()
                ->where('starred_type', $counsellor->getMorphClass())
                ->where('starred_id', $counsellor->id)
                ->where('source', StarSourceEnum::postPublished->value)
                ->where('created_at', '>=', $from)
                ->where('created_at', '<', $from->copy()->addDay())
                ->count();

            if ($starredToday >= (int) config('recognition.posts_per_day')) {
                return null;
            }

            return AwardCounsellorStarAction::new()->execute(
                $counsellor, StarSourceEnum::postPublished, $post, "post_published:{$post->id}",
            );
        });
    }
}
