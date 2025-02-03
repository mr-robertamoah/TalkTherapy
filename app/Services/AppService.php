<?php

namespace App\Services;

use App\Enums\DiscussionStatusEnum;
use App\Enums\SessionStatusEnum;
use App\Events\DiscussionStartedEvent;
use App\Events\SessionStartedEvent;
use App\Models\Alert;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\Report;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Models\Visitor;
use App\Notifications\DiscussionDueNotification;
use App\Notifications\DiscussionFailedNotification;
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
        $superAdmin = User::query()->whereSuperAdmin()->first();

        $superAdmin->notify( new VisitorsStatusNotification());
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

    public function broadcastStartedSessionsAndDiscussions()
    {
        $alerts = Alert::query()
            ->whereWaiting()
            ->with(['alertable' => function (MorphTo $query) {
                $query->morphWith([
                    Therapy::class => ['sessions.addedby', 'sessions.addedby.user', 'discussions.addedby'],
                    GroupTherapy::class => ['sessions.addedby', 'discussions.addedby'],
                ]);
            }, 'user'])
            ->get();
        // ds('alerts', $alerts);

        $alerts->each(function ($alert) {
            $activeSession = $alert->alertable?->activeSession;

            if ($activeSession)
                SessionStartedEvent::broadcast($activeSession);

            $activeDiscussion = $alert->alertable?->activeDiscussion;

            if ($activeDiscussion)
                DiscussionStartedEvent::broadcast($activeDiscussion);
            // ds('activeDiscussion', $activeDiscussion);
            // ds('activeSession', $activeSession);
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

    public function notifyParticipantsOfStartingDiscussions()
    {
        $this->alertDiscussionParticipants();
    }

    private function alertDiscussionParticipants()
    {
        $discussions = Discussion::query()
            ->whereAboutToStart()
            ->with(['for' => function (MorphTo $morphTo) {
                $morphTo->morphWith([
                    Therapy::class => ['addedby', 'counsellor.user'], // TODO auto load group therapy
                ]);
            }])
            ->get();

        // ds('discussions', $discussions);
        $discussions->each(function ($discussion) {
            Notification::send(
                $discussion->counsellors, 
                new DiscussionDueNotification($discussion)
            );
        });

    }

    public function failUnheldDiscussions()
    {
        $discussions = Discussion::query()
            ->whereStatusIn([
                DiscussionStatusEnum::pending->value,
            ])
            ->wherePastEndTime()
            ->get();
            
        $discussions->each(function ($discussion) {
            Notification::send(
                $discussion->counsellors, 
                new DiscussionFailedNotification($discussion)
            );

            $discussion->status = DiscussionStatusEnum::failed->value;
            $discussion->save();
        });
    }

    public function getStats()
    {
        return ['stats' => [
            'counsellors' => Counsellor::count(),
            'users' => User::count(),
            'therapies' => Therapy::count(),
        ]];
    }
}