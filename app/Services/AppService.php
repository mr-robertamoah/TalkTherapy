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
use App\Notifications\QueueJobFailedNotification;
use App\Notifications\ReportNotification;
use App\Notifications\SessionDueNotification;
use App\Notifications\SessionFailedNotification;
use App\Notifications\VisitorsStatusNotification;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class AppService extends Service
{
    public function alertSuperAdminWithStatus()
    {
        $superAdmin = User::query()->whereSuperAdmin()->first();

        $superAdmin->notify(new VisitorsStatusNotification);
    }

    public function alertAdminWithReport(Report $report)
    {
        $admins = User::query()->whereAdmin()->inRandomOrder()->limit(2)->get();

        Notification::send($admins->unique(), new ReportNotification($report));
    }

    // SCRUM-82: a job reaching Queue::failing() has already exhausted its retries, so this is
    // the last chance to surface it -- logged first, ahead of the try/catch, so it's captured
    // even if notifying admins itself fails (this still relies on the log channel itself not
    // throwing, but that's true of every other Log::error() call in this codebase too), then a
    // best-effort admin email on top.
    public function alertAdminsOfFailedJob(JobFailed $jobFailed): void
    {
        Log::error('A queued job failed.', [
            'connection' => $jobFailed->connectionName,
            'queue' => $jobFailed->job->getQueue(),
            'job' => $jobFailed->job->resolveName(),
            'exception' => $jobFailed->exception->getMessage(),
        ]);

        try {
            $admins = User::query()->whereAdmin()->inRandomOrder()->limit(2)->get();

            Notification::send($admins->unique(), new QueueJobFailedNotification(
                $jobFailed->job->resolveName(),
                $jobFailed->connectionName,
                $jobFailed->job->getQueue(),
                $jobFailed->exception->getMessage(),
            ));
        } catch (Throwable $exception) {
            // Never let the alerting path itself crash the queue worker -- log and move on.
            Log::error('Failed to notify admins about a failed queue job.', [
                'exception' => $exception->getMessage(),
            ]);
        }
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

        $alerts->each(function ($alert) {
            $activeSession = $alert->alertable?->activeSession;

            if ($activeSession) {
                SessionStartedEvent::broadcast($activeSession);
            }

            $activeDiscussion = $alert->alertable?->activeDiscussion;

            if ($activeDiscussion) {
                DiscussionStartedEvent::broadcast($activeDiscussion);
            }
            $alert->delete();
        });
    }

    public function failUnheldSessions()
    {
        $sessions = Session::query()
            ->whereStatusIn([
                SessionStatusEnum::pending->value,
                SessionStatusEnum::in_session_confirmation->value,
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
