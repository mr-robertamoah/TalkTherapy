<?php

namespace App\Actions\Post;

use App\Actions\Action;
use App\Actions\Star\AwardCounsellorStarAction;
use App\Actions\Star\RevokeCounsellorStarsAction;
use App\Enums\StarSourceEnum;
use App\Models\Counsellor;
use App\Models\Post;
use App\Models\Star;
use App\Models\User;
use App\Support\BestEffortAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

// TT-7.11c/SCRUM-472: a qualifying user who likes or comments on a counsellor's post (or both, any number of times)
// gives that counsellor one star -- key post_interaction:{post}:{user}, starredby the user. Recomputed from scratch
// after every like, unlike, comment and comment delete: while the user has a like or a comment on the post and
// qualifies, the star is made sure of (under StarCapPolicy's per-post and per-day caps); otherwise it is taken back.
// A post made as a user (not a counsellor) earns nothing. Returns the star the user now gives, or null.
//
// Runs under the post counsellor's row lock -- the same lock, taken first, that AwardCounsellorStarAction takes (its
// own lockForUpdate re-enters it), so concurrent syncs for one counsellor run one at a time and see each other's
// result, and there is only one lock to order.
class SyncPostInteractionStarAction extends Action
{
    // Run after the caller's commit, never blocking or undoing the like or comment. $thing is what was liked or
    // commented on; anything but a Post is ignored.
    public static function afterCommit(?Model $thing, ?User $user): void
    {
        if (! $thing instanceof Post || ! $user) {
            return;
        }

        BestEffortAfterCommit::run(fn () => static::new()->execute($thing, $user));
    }

    public static function awardKey(Post $post, User $user): string
    {
        return "post_interaction:{$post->getKey()}:{$user->getKey()}";
    }

    public function execute(Post $post, User $user): ?Star
    {
        $counsellor = $post->addedby;

        if (! $counsellor instanceof Counsellor) {
            return null;
        }

        return DB::transaction(function () use ($post, $user, $counsellor) {
            Counsellor::withTrashed()->whereKey($counsellor->getKey())->lockForUpdate()->first();

            $key = static::awardKey($post, $user);

            if (! $this->hasInteracted($post, $user) || ! static::qualifies($counsellor, $user)) {
                RevokeCounsellorStarsAction::new()->forCounsellorAndKey($counsellor, $key);

                return null;
            }

            $existing = Star::query()
                ->where('starred_type', $counsellor->getMorphClass())
                ->where('starred_id', $counsellor->getKey())
                ->where('award_key', $key)
                ->first();

            return $existing ?? AwardCounsellorStarAction::new()->execute(
                $counsellor, StarSourceEnum::postInteraction, $post, $key, $user,
            );
        });
    }

    // A like or a comment (anonymous ones included) -- duplicate likes are still one interaction.
    private function hasInteracted(Post $post, User $user): bool
    {
        return $post->likes()->where('user_id', $user->getKey())->exists()
            || $post->comments()->where('user_id', $user->getKey())->exists();
    }

    // Whose likes and comments earn a star: not the post's author and not a counsellor at all (a deleted counsellor
    // profile still counts as one), with a verified email, an account at least
    // recognition.interaction_min_account_age_days old, not suspended in either scope, and not deleted or closed.
    // Read fresh, so a change since the request is seen.
    public static function qualifies(Counsellor $author, User $user): bool
    {
        $user = User::withTrashed()->find($user->getKey());

        if (! $user || $user->trashed() || $user->anonymised_at || $user->getKey() === $author->user_id) {
            return false;
        }

        if (! $user->email_verified_at || ! $user->created_at) {
            return false;
        }

        if ($user->created_at->greaterThan(now()->subDays((int) config('recognition.interaction_min_account_age_days')))) {
            return false;
        }

        if ($user->anyActiveSuspension()) {
            return false;
        }

        return ! Counsellor::withTrashed()->where('user_id', $user->getKey())->exists();
    }
}
