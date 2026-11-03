<?php

namespace App\Actions\Discussion;

use App\Actions\Action;
use App\Enums\DiscussionStatusEnum;
use App\Models\Discussion;
use App\Notifications\DiscussionFailedNotification;
use Illuminate\Support\Facades\Notification;

// Scheduled every four hours: a pending discussion past its end is marked failed, and everyone in it
// (its creator included, SCRUM-372) is told. SCRUM-374: moved out of AppService.
class FailUnheldDiscussionsAction extends Action
{
    public function execute(): void
    {
        Discussion::query()
            ->whereStatusIn([DiscussionStatusEnum::pending->value])
            ->wherePastEndTime()
            ->get()
            ->each(function (Discussion $discussion) {
                Notification::send($discussion->participantCounsellors(), new DiscussionFailedNotification($discussion));

                $discussion->status = DiscussionStatusEnum::failed->value;
                $discussion->save();
            });
    }
}
