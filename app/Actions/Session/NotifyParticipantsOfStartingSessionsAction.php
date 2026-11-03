<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\Models\Session;
use App\Models\Therapy;
use App\Notifications\SessionDueNotification;
use App\Support\SchedulerOnce;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Notification;

// Scheduled every five minutes (routes/console.php): "your session starts in under 30 minutes".
// SCRUM-372: once per session -- each participant used to get it (and its email) up to six times.
// SCRUM-374: moved out of AppService.
class NotifyParticipantsOfStartingSessionsAction extends Action
{
    public function execute(): void
    {
        Session::query()
            ->whereAboutToStart()
            ->with(['for' => fn (MorphTo $morphTo) => $morphTo->morphWith([
                Therapy::class => ['addedby', 'counsellor.user'],
            ])])
            ->get()
            ->each(function (Session $session) {
                if (SchedulerOnce::first('session-due', $session)) {
                    Notification::send($session->users, new SessionDueNotification($session));
                }
            });
    }
}
