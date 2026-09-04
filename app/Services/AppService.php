<?php

namespace App\Services;

use App\Actions\Organization\SettleOrganizationInvoiceAction;
use App\Enums\DiscussionStatusEnum;
use App\Enums\OrganizationInvoiceStatusEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\SessionStatusEnum;
use App\Events\DiscussionStartedEvent;
use App\Events\SessionStartedEvent;
use App\Models\Alert;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\Organization;
use App\Models\OrganizationInvoice;
use App\Models\Report;
use App\Models\Request;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Models\Visitor;
use App\Notifications\DiscussionDueNotification;
use App\Notifications\DiscussionFailedNotification;
use App\Notifications\OrganizationCounsellorCompensationChangeExpiredNotification;
use App\Notifications\OrganizationCounsellorCompensationChangeExpiryReminderNotification;
use App\Notifications\QueueJobFailedNotification;
use App\Notifications\ReportNotification;
use App\Notifications\SessionDueNotification;
use App\Notifications\SessionFailedNotification;
use App\Notifications\VisitorsStatusNotification;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
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

    // SCRUM-134: a Counsellor is only soft-deleted at deletion time (see DeleteCounsellorAction) so
    // the grace period gives a window to notice and undo an accidental/malicious deletion.
    // Permanently removed once that window (config('counsellor.deletion_grace_period_days'),
    // default 60) has passed. Only the Counsellor row itself is force-deleted -- related
    // historical records (therapies, sessions, licenses, testimonials) are left untouched, same
    // as they already are for a merely-soft-deleted counsellor.
    public function purgeExpiredSoftDeletedCounsellors()
    {
        Counsellor::onlyTrashed()
            ->where('deleted_at', '<=', now()->subDays(config('counsellor.deletion_grace_period_days')))
            ->get()
            ->each(fn (Counsellor $counsellor) => $counsellor->forceDelete());
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

    // SCRUM-149 (TT-6.4c): sent once, ~2 days before a pending compensation-change request's
    // expires_at -- reminder_sent_at (not day-arithmetic alone) makes this exactly-once
    // regardless of how many times or how imprecisely this daily sweep actually runs.
    //
    // Security review (PR #87, post-merge): each row is re-locked and re-checked for `pending`
    // immediately before writing, mirroring RespondToOrganizationCounsellorCompensationRequestAction
    // /CounterOfferOrganizationCounsellorCompensationChangeAction -- without this, a concurrent
    // accept/reject/counter-offer landing between this method's initial batch SELECT and its
    // per-row UPDATE could be silently clobbered. Each row is also isolated in its own try/catch
    // so one bad row (e.g. an unresolvable `to`) can't abort every other pending negotiation's
    // reminder for the day.
    public function sendCompensationRequestExpiryReminders()
    {
        Request::query()
            ->whereType(RequestTypeEnum::organizationCounsellorCompensationChange->value)
            ->wherePending()
            ->whereNull('reminder_sent_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addDays(2))
            ->get()
            ->each(function (Request $request) {
                try {
                    DB::transaction(function () use ($request) {
                        $locked = Request::query()->lockForUpdate()->findOrFail($request->id);

                        if ($locked->status !== RequestStatusEnum::pending->value || $locked->reminder_sent_at) {
                            return;
                        }

                        // A same-day "2 days before" reminder for an offer whose whole window
                        // was under 3 days adds noise, not value -- and guards against a
                        // malformed expires_at <= created_at pair ever reaching Carbon's
                        // absolute-value diffInDays(), which can't distinguish "3 days" from
                        // "-3 days".
                        if ($locked->expires_at->lessThanOrEqualTo($locked->created_at)
                            || $locked->created_at->diffInDays($locked->expires_at) < 3) {
                            return;
                        }

                        $this->notifyCompensationRequestRecipient(
                            $locked,
                            new OrganizationCounsellorCompensationChangeExpiryReminderNotification($locked)
                        );

                        $locked->update(['reminder_sent_at' => now()]);
                    });
                } catch (Throwable $exception) {
                    Log::error('Failed to send a compensation-change expiry reminder.', [
                        'requestId' => $request->id,
                        'exception' => $exception->getMessage(),
                    ]);
                }
            });
    }

    // SCRUM-149 (TT-6.4c): silence is never a valid stalling strategy in either direction -- a
    // pending negotiation past its expires_at auto-resolves to the existing `rejected` status
    // (not a new enum case; functionally identical to a manual reject everywhere except copy),
    // never touching the affiliation's status or existing terms (same fairness-critical
    // guarantee as SCRUM-147's manual reject).
    //
    // Security review (PR #87, post-merge): same lock-then-recheck-then-write and per-row
    // isolation as the reminder sweep above, for the same reasons.
    public function expireStaleCompensationRequests()
    {
        Request::query()
            ->whereType(RequestTypeEnum::organizationCounsellorCompensationChange->value)
            ->wherePending()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get()
            ->each(function (Request $request) {
                try {
                    DB::transaction(function () use ($request) {
                        $locked = Request::query()->lockForUpdate()->findOrFail($request->id);

                        if ($locked->status !== RequestStatusEnum::pending->value) {
                            return;
                        }

                        $locked->update([
                            'status' => RequestStatusEnum::rejected->value,
                            // SCRUM-150 will need to tell a manual reject apart from an expiry in
                            // its read-only negotiation-state copy, without a new
                            // RequestStatusEnum case.
                            'data' => array_merge($locked->data, ['resolvedBy' => 'expiry']),
                        ]);

                        $this->notifyCompensationRequestRecipient(
                            $locked,
                            new OrganizationCounsellorCompensationChangeExpiredNotification($locked)
                        );
                    });
                } catch (Throwable $exception) {
                    Log::error('Failed to auto-expire a compensation-change request.', [
                        'requestId' => $request->id,
                        'exception' => $exception->getMessage(),
                    ]);
                }
            });
    }

    // TT-7.3b-e/SCRUM-236: finds every retainer invoice whose period has closed and is still
    // `open`, settling each independently -- try/catch per invoice (same isolation as the two
    // compensation-request sweeps above) so one org's failure (a missing payment instrument, a
    // Paystack error) can never block or crash settlement for every other org's invoice in the
    // same run. SettleOrganizationInvoiceAction itself does the real claim-and-dispatch work;
    // this is only the periodic trigger.
    public function settleDueOrganizationInvoices()
    {
        OrganizationInvoice::query()
            ->where('status', OrganizationInvoiceStatusEnum::open->value)
            // A plain Y-m-d string, matching how period_end is actually stored (the model's own
            // `date:Y-m-d` cast, not a bare 'date' cast) -- comparing against a raw Carbon
            // instance here would bind its full datetime string representation instead, which
            // still happens to string-compare correctly against a same-day period_end but is
            // needlessly fragile to rely on.
            ->where('period_end', '<', now()->toDateString())
            ->get()
            ->each(function (OrganizationInvoice $invoice) {
                try {
                    SettleOrganizationInvoiceAction::new()->execute($invoice);
                } catch (Throwable $exception) {
                    Log::error('Failed to settle an organization retainer invoice.', [
                        'organizationInvoiceId' => $invoice->id,
                        'exception' => $exception->getMessage(),
                    ]);
                }
            });
    }

    // Mirrors CounterOfferOrganizationCounsellorCompensationChangeAction's own notify helper
    // (SCRUM-148) -- `to` alternates between a Counsellor and the Organization itself, and
    // Organization isn't Notifiable, so every one of its admins is notified individually.
    // Security review (PR #87, post-merge): `to` can be null if the counterparty was
    // soft-deleted while a negotiation was still pending (a real, already-supported lifecycle --
    // see purgeExpiredSoftDeletedCounsellors() above) -- logged and skipped rather than left to
    // fatal on a null method call, and an org with no (remaining) admins is logged rather than
    // silently no-op'd.
    private function notifyCompensationRequestRecipient(Request $request, $notification): void
    {
        if (is_null($request->to)) {
            Log::warning('Compensation-change request has no resolvable recipient to notify.', [
                'requestId' => $request->id,
            ]);

            return;
        }

        if ($request->to instanceof Organization) {
            $admins = $request->to->admins;

            if ($admins->isEmpty()) {
                Log::warning('Compensation-change request\'s organization has no admins to notify.', [
                    'requestId' => $request->id,
                    'organizationId' => $request->to->id,
                ]);

                return;
            }

            Notification::send($admins, $notification);

            return;
        }

        $request->to->notify($notification);
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
