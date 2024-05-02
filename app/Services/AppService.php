<?php

namespace App\Services;

use App\Enums\SessionStatusEnum;
use App\Events\SessionStartedEvent;
use App\Models\Alert;
use App\Models\GroupTherapy;
use App\Models\Report;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Models\Visitor;
use App\Notifications\ReportNotification;
use App\Notifications\SessionDueNotification;
use App\Notifications\SessionFailedNotification;
use App\Notifications\SessionStartedNotification;
use App\Notifications\VisitorsStatusNotification;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Notification;

class AppService extends Service
{
    public function alertSuperAdminWithStatus()
    {
        $superAdmins = User::query()->whereSuperAdmin()->get();

        Notification::send($superAdmins->unique(), new VisitorsStatusNotification());
    }

    public function alertAdminWithReport(Report $report)
    {
        $admins = User::query()->whereAdmin()->inRandomOrder()->limit(2)->get();

        Notification::send($admins->unique(), new ReportNotification($report));
    }
    
    public function clearVisitors()
    {
        Visitor::query()
            ->whereUser()
            ->orWhere(function ($query) {
                $query->whereNonUser();
            })
            ->delete();
    }

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
            Notification::send(
                $session->users, 
                new SessionDueNotification($session)
            );
        });

    }

    public function broadcastStartedSessions()
    {
        $alerts = Alert::query()
            ->whereWaiting()
            ->with(['alertable' => function (MorphTo $query) {
                $query->morphWith([
                    Therapy::class => ['sessions.addedby', 'sessions.addedby.user'],
                    GroupTherapy::class => ['sessions.addedby',],
                ]);
            }, 'user'])
            ->get();

        $alerts->each(function ($alert) {
            $activeSession = $alert->alertable->activeSession;

            if (!$activeSession) return;
            
            SessionStartedEvent::broadcast($activeSession);
            $alert->delete();
        });
    }

    public function failUnheldSessions()
    {
        $sessions = Session::query()
            ->whereStatusIn([
                SessionStatusEnum::pending->value,
                SessionStatusEnum::in_session_confirmation->value
            ])
            ->wherePastEndTime()
            ->get();
            
        $sessions->each(function ($session) {
            Notification::send(
                $session->users, 
                new SessionFailedNotification($session)
            );

            $session->status = SessionStatusEnum::failed->value;
            $session->save();
        });
    }
}