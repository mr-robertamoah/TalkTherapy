<?php

namespace App\Services;

use App\Enums\SessionStatusEnum;
use App\Events\SessionStartedEvent;
use App\Models\Alert;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\SessionDueNotification;
use App\Notifications\SessionStartedNotification;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Notification;

class AppService extends Service
{
    public function notifyParticipantsOfStartingSessions()
    {
        $this->alertSessionParticipants();
    }

    private function alertSessionParticipants()
    {
        $sessions = Session::query()
            ->whereAboutToStart()
            ->with(['for' => function (MorphTo $morphTo) {
                $morphTo->morphWith([
                    Therapy::class => ['addedby', 'counsellor.user'],
                ]);
            }])
            ->get();
        
        $sessions->each(function ($session) {
            Notification::send($session->users, new SessionDueNotification($session));
        });

    }

    public function broadcastStartedSessions()
    {
        $alerts = Alert::query()
            ->whereWaiting()
            ->with(['alertable' => function (MorphTo $query) {
                $query->morphWith([
                    Therapy::class => ['sessions.addedby', 'sessions.counsellor.user'],
                    Therapy::class => ['sessions.addedby',],
                ]);
            }, 'user'])
            ->get();

        $alerts->each(function ($alert) {
            $activeSession = $alert->alertable->activeSession;

            if (!$activeSession) return;
            
            SessionStartedEvent::broadcast($activeSession);
        });

        $alerts->delete();
    }

    public function failUnheldSessions()
    {
        Session::query()
            ->whereStatusIn([
                SessionStatusEnum::pending->value
            ])
            ->wherePastEndTime()
            ->update([
                'status' => SessionStatusEnum::failed->value
            ]);
    }
}