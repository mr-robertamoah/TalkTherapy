<?php

namespace App\Actions\Post;

use App\Actions\Action;
use App\Actions\Star\AwardCounsellorStarAction;
use App\Actions\Star\RevokeCounsellorStarsAction;
use App\Enums\StarSourceEnum;
use App\Models\Counsellor;
use App\Models\Post;
use App\Models\PostInteractionAward;
use App\Models\Star;
use App\Models\User;
use App\Support\BestEffortAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

// TT-7.11c/SCRUM-472: a qualifying user who likes or comments on a counsellor's post (or both, any number of times)
// gives that counsellor one star -- key post_interaction:{post}:{user}, starredby the user. Recomputed after every
// like, unlike, comment and comment delete: while the user has a like or a comment on the post and qualifies, the star
// stays; once they have neither, or stop qualifying, it is taken back. A post made as a user (not a counsellor) earns
// nothing. Returns the star the user now gives, or null.
//
// Security review M1: each award is recorded for good in post_interaction_awards, in the award's transaction. A user
// with an award for the post is never awarded for it again, so unliking and liking again gives nothing new (and can't
// move a star into a new month), and StarCapPolicy's interaction caps count those records.
//
// Changes run under the post counsellor's row lock -- the same lock, taken first, that AwardCounsellorStarAction takes
// (its own lockForUpdate re-enters it), so there is only one lock to order. Security review M2: the common cases --
// a user who doesn't qualify and holds no star, or one already awarded who still qualifies and interacts -- are
// answered without taking it.
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
        $counsellor = static::counsellorOf($post);

        if (! $counsellor) {
            return null;
        }

        $key = static::awardKey($post, $user);
        $qualifies = static::qualifies($user);

        if (! $qualifies && ! $this->star($counsellor, $key)) {
            return null;
        }

        if ($qualifies && $this->hasInteracted($post, $user) && $this->awarded($post, $user)) {
            return $this->star($counsellor, $key);
        }

        return DB::transaction(function () use ($post, $user, $counsellor, $key) {
            Counsellor::withTrashed()->whereKey($counsellor->getKey())->lockForUpdate()->first();

            if (! $this->hasInteracted($post, $user) || ! static::qualifies($user)) {
                RevokeCounsellorStarsAction::new()->forCounsellorAndKey($counsellor, $key);

                return null;
            }

            if ($this->awarded($post, $user)) {
                return $this->star($counsellor, $key);
            }

            $star = AwardCounsellorStarAction::new()->execute(
                $counsellor, StarSourceEnum::postInteraction, $post, $key, $user,
            );

            if ($star) {
                PostInteractionAward::query()->create([
                    'post_id' => $post->getKey(),
                    'user_id' => $user->getKey(),
                    'counsellor_id' => $counsellor->getKey(),
                    'awarded_at' => now(),
                ]);
            }

            return $star;
        });
    }

    // The post's counsellor, a deleted one included, so an unlike on their post still takes the star back
    // (AwardCounsellorStarAction gives a deleted counsellor nothing). Null for a post made as a user.
    public static function counsellorOf(Post $post): ?Counsellor
    {
        return $post->addedby_type === (new Counsellor)->getMorphClass()
            ? Counsellor::withTrashed()->find($post->addedby_id)
            : null;
    }

    // A like or a comment (anonymous ones included) -- duplicate likes are still one interaction.
    private function hasInteracted(Post $post, User $user): bool
    {
        return $post->likes()->where('user_id', $user->getKey())->exists()
            || $post->comments()->where('user_id', $user->getKey())->exists();
    }

    private function awarded(Post $post, User $user): bool
    {
        return PostInteractionAward::query()->where('post_id', $post->getKey())->where('user_id', $user->getKey())->exists();
    }

    private function star(Counsellor $counsellor, string $key): ?Star
    {
        return Star::query()
            ->where('starred_type', $counsellor->getMorphClass())
            ->where('starred_id', $counsellor->getKey())
            ->where('award_key', $key)
            ->first();
    }

    // Whose likes and comments earn a star: not a counsellor at all -- which also rules out the post's author -- (a
    // deleted counsellor profile still counts as one), with a verified email, an account at least
    // recognition.interaction_min_account_age_days old, not suspended in either scope, and not deleted or anonymised.
    // Read fresh, so a change since the request is seen.
    public static function qualifies(User $user): bool
    {
        $user = User::withTrashed()->find($user->getKey());

        if (! $user || $user->trashed() || $user->anonymised_at) {
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
