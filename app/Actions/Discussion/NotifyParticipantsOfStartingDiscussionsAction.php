<?php

namespace App\Actions\Discussion;

use App\Actions\Action;
use App\Models\Discussion;
use App\Models\Therapy;
use App\Notifications\DiscussionDueNotification;
use App\Support\SchedulerOnce;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Notification;

// Scheduled every minute: "your discussion starts in under 30 minutes". SCRUM-372: once per
// discussion (it used to repeat every minute for half an hour), and to its creator too.
// SCRUM-374: moved out of AppService.
class NotifyParticipantsOfStartingDiscussionsAction extends Action
{
    public function execute(): void
    {
        Discussion::query()
            ->whereAboutToStart()
            ->with(['for' => fn (MorphTo $morphTo) => $morphTo->morphWith([
                Therapy::class => ['addedby', 'counsellor.user'],
            ])])
            ->get()
            ->each(function (Discussion $discussion) {
                if (SchedulerOnce::first('discussion-due', $discussion)) {
                    Notification::send($discussion->participantCounsellors(), new DiscussionDueNotification($discussion));
                }
            });
    }
}
