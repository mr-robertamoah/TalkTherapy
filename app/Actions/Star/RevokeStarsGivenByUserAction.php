<?php

namespace App\Actions\Star;

use App\Actions\Action;
use App\Enums\StarSourceEnum;
use App\Models\Star;
use App\Models\User;
use Illuminate\Support\Carbon;

// TT-7.11c/SCRUM-472 (security review L1, L2): stars a person gave through their likes and comments go when they can't
// give them any more. Their post_interaction_awards stay, so the same likes never earn the star again. Each returns how
// many stars were deleted.
class RevokeStarsGivenByUserAction extends Action
{
    // Suspended (either scope): their post-interaction stars from the current UTC month -- the month recognition is
    // still counting (closed months are snapshotted when approved).
    public function onSuspension(User $user): int
    {
        $from = Carbon::now('UTC')->startOfMonth()->setTimezone(config('app.timezone'));

        return $this->interactionStars($user)->where('created_at', '>=', $from)->delete();
    }

    // Account closed or anonymised: every post-interaction star they gave, and their name comes off every other star
    // (starredby nulled -- e.g. a held session's, which stays the counsellor's).
    public function onAccountClosed(User $user): int
    {
        $deleted = $this->interactionStars($user)->delete();

        Star::query()
            ->where('starredby_type', $user->getMorphClass())
            ->where('starredby_id', $user->getKey())
            ->update(['starredby_type' => null, 'starredby_id' => null]);

        return $deleted;
    }

    private function interactionStars(User $user)
    {
        return Star::query()
            ->where('source', StarSourceEnum::postInteraction->value)
            ->where('starredby_type', $user->getMorphClass())
            ->where('starredby_id', $user->getKey());
    }
}
