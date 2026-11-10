<?php

namespace App\Actions\Alert;

use App\Actions\Action;
use App\Events\DiscussionStartedEvent;
use App\Events\SessionStartedEvent;
use App\Models\Alert;
use App\Support\SchedulerOnce;

// Scheduled every minute: for every therapy someone is waiting on (an Alert), tell its open pages
// that its session or discussion has started (SCRUM-369):
//  - one pass per therapy, not per waiting person;
//  - each session and discussion is announced once -- a page loaded after that already has it
//    (TherapyTrait's activeSession and getActiveSession share Session::whereActive);
//  - a finished session (abandoned, held) is not active any more.
// SCRUM-374: moved out of AppService.
class BroadcastStartedSessionsAndDiscussionsAction extends Action
{
    public function execute(): void
    {
        Alert::query()
            ->whereWaiting()
            ->with('alertable')
            ->get()
            ->groupBy(fn (Alert $alert) => "{$alert->alertable_type}:{$alert->alertable_id}")
            ->each(function ($alerts) {
                $alertable = $alerts->first()->alertable;

                $activeSession = $alertable?->activeSession;
                if ($activeSession && SchedulerOnce::first('session-started', $activeSession)) {
                    SessionStartedEvent::broadcast($activeSession);
                }

                $activeDiscussion = $alertable?->activeDiscussion;
                if ($activeDiscussion && SchedulerOnce::first('discussion-started', $activeDiscussion)) {
                    DiscussionStartedEvent::broadcast($activeDiscussion);
                }

                Alert::query()->whereKey($alerts->modelKeys())->delete();
            });
    }
}
