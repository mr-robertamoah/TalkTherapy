<?php

namespace App\Actions\Discussion;

use App\Actions\Action;
use App\Actions\Star\AwardCounsellorStarAction;
use App\Enums\DiscussionStatusEnum;
use App\Enums\StarSourceEnum;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\User;

// TT-7.11b/SCRUM-471: a discussion held for the first time earns each counsellor who ran it (its creator and every added
// counsellor) one star -- but only if it really took place: messages from at least 2 different people sent within its
// start and end times. A person writing as their user and as their counsellor profile is one person. Key
// discussion_held:{id}, unique per counsellor. Called after commit by DiscussionService::endDiscussion, only the first
// time (discussions.held_at).
class AwardDiscussionHeldStarAction extends Action
{
    private const MIN_SENDERS = 2;

    public function execute(Discussion $discussion): void
    {
        $discussion = $discussion->fresh();

        if (! $discussion || $discussion->status !== DiscussionStatusEnum::held->value || ! $this->hasEnoughSenders($discussion)) {
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
        if (! $discussion->start_time || ! $discussion->end_time) {
            return false;
        }

        $senders = $discussion->messages()
            ->whereBetween('created_at', [$discussion->start_time, $discussion->end_time])
            ->select(['from_type', 'from_id'])
            ->distinct()
            ->get();

        // Counsellor senders resolved to their users in one query.
        $counsellorUsers = Counsellor::withTrashed()
            ->whereKey($senders->where('from_type', Counsellor::class)->pluck('from_id')->all())
            ->pluck('user_id', 'id');

        return $senders
            ->map(fn ($message) => match ($message->from_type) {
                Counsellor::class => 'user:'.($counsellorUsers[$message->from_id] ?? "counsellor-{$message->from_id}"),
                User::class => 'user:'.$message->from_id,
                default => "{$message->from_type}:{$message->from_id}",
            })
            ->unique()
            ->count() >= self::MIN_SENDERS;
    }
}
