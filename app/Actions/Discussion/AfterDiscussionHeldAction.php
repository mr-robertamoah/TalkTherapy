<?php

namespace App\Actions\Discussion;

use App\Actions\Action;
use App\Actions\Star\AwardCounsellorStarAction;
use App\Enums\StarSourceEnum;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\User;

// TT-7.11b/SCRUM-471: a held discussion earns each counsellor who ran it (its creator and every added counsellor)
// one star -- but only if it really took place: messages from at least 2 different people. A person writing as their
// user and as their counsellor profile is one person. The award key is per discussion and unique per counsellor, so
// ending it again gives nobody a second star.
class AfterDiscussionHeldAction extends Action
{
    private const MIN_SENDERS = 2;

    public function execute(Discussion $discussion): void
    {
        if (! $this->hasEnoughSenders($discussion)) {
            return;
        }

        foreach ($discussion->participantCounsellors() as $counsellor) {
            AwardCounsellorStarAction::new()->execute(
                $counsellor, StarSourceEnum::discussionHeld, $discussion, "discussion_held:{$discussion->id}",
            );
        }
    }

    private function hasEnoughSenders(Discussion $discussion): bool
    {
        $senders = $discussion->messages()
            ->select(['from_type', 'from_id'])
            ->distinct()
            ->get();

        $people = $senders
            ->map(fn ($message) => match ($message->from_type) {
                (new Counsellor)->getMorphClass() => 'user:'.Counsellor::withTrashed()->whereKey($message->from_id)->value('user_id'),
                (new User)->getMorphClass() => 'user:'.$message->from_id,
                default => "{$message->from_type}:{$message->from_id}",
            })
            ->unique();

        return $people->count() >= self::MIN_SENDERS;
    }
}
