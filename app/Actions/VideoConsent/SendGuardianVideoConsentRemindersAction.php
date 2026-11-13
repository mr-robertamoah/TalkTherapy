<?php

namespace App\Actions\VideoConsent;

use App\Actions\Action;
use App\Actions\Therapy\GetTherapyClientsAction;
use App\Actions\Video\EnsureVideoIsAvailableForSessionAction;
use App\Enums\SessionStatusEnum;
use App\Enums\VideoConsentReminderWindowEnum;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\User;
use App\Models\VideoConsentReminder;
use App\Notifications\GuardianVideoConsentReminderNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

// TT-3.1e-e/SCRUM-284: reminds a ward's guardians that video consent is still outstanding for an
// upcoming online therapy session -- SCRUM-313: or group session, for each minor member without consent, per ward --
// once per window:
//  - 'day_before': ~a day before start_time -- same exactly-once-via-a-timestamp-column shape as
//    SendCompensationRequestExpiryRemindersAction (VideoConsentReminder.day_before_sent_at instead
//    of Request.reminder_sent_at), same lock-then-recheck-then-write and per-row isolation for the
//    same reasons. Window is "start_time within the next 24 hours" rather than pinned to exactly
//    24h before, since this runs dailyAt() once a day -- a tighter window would risk sessions
//    slipping through if the schedule is ever delayed.
//  - 'hour_before': ~an hour before start_time. Runs everyFiveMinutes() (see routes/console.php),
//    the same cadence NotifyParticipantsOfStartingSessionsAction already uses for its own "about
//    to start" window.
// SCRUM-376: moved out of AppService.
class SendGuardianVideoConsentRemindersAction extends Action
{
    public function execute(VideoConsentReminderWindowEnum $reminderWindow): void
    {
        $window = $reminderWindow->value;
        $column = $reminderWindow->sentAtColumn();
        $upperBound = $reminderWindow->upperBound();

        // SCRUM-313: one-to-one and group sessions -- a group's minors are each reminded about, per ward (a reminder
        // row is one session and one ward), so a session stays in the sweep until every ward's reminder has gone.
        Session::query()
            ->where(fn ($query) => $query
                // A one-to-one session has one ward: done once its reminder for this window has gone.
                ->where(fn ($q) => $q->whereTherapy()->whereDoesntHave('videoConsentReminders', fn ($r) => $r->whereNotNull($column)))
                ->orWhere(fn ($q) => $q->whereGroupTherapy()))
            ->whereOnline()
            ->where('status', SessionStatusEnum::pending->value)
            ->where('start_time', '>', now())
            ->where('start_time', '<=', $upperBound)
            ->with('for')
            ->get()
            ->each(function (Session $session) use ($window, $column) {
                // Who might need reminding, worked out before any lock: most sessions need none (an adult's, a group with
                // no minors, consent already given), and those shouldn't lock a session row about to start.
                foreach ($this->wardsNeedingConsent($session) as $ward) {
                    $this->remind($session, $ward, $window, $column);
                }
            });
    }

    // One ward on their own (SCRUM-313 review): a failure for one member of a group mustn't keep the others' guardians
    // from being reminded.
    private function remind(Session $session, User $ward, string $window, string $column): void
    {
        try {
            DB::transaction(function () use ($session, $ward, $window, $column) {
                // Reviewer finding (2026-09-11): lockForUpdate() on a VideoConsentReminder
                // row that doesn't exist yet (this session's very first reminder) locks
                // nothing -- the exact bug class already found and fixed in
                // GrantVideoConsentAction (see decision-log.md's SCRUM-281 entry). Locking
                // the guaranteed-already-existing Session row first serializes two
                // overlapping sweep runs correctly regardless of isolation level.
                Session::query()->lockForUpdate()->find($session->id);

                $reminder = VideoConsentReminder::query()->firstOrCreate(['session_id' => $session->id, 'ward_id' => $ward->id]);

                // Re-checked under the lock: sent by an overlapping run, or consent given meanwhile.
                if ($reminder->{$column} || ! $this->wardsNeedingConsent($session)->contains(fn (User $needing) => $needing->is($ward))) {
                    return;
                }

                // SCRUM-313 (security review): only guardians who count (SCRUM-288) -- a suspended or under-18 guardian
                // can't give consent, and isn't told about the ward's sessions.
                $guardians = $ward->activeGuardianships()->with('guardian')->get()->pluck('guardian')->filter();

                if ($guardians->isEmpty()) {
                    return;
                }

                Notification::send($guardians, new GuardianVideoConsentReminderNotification(
                    $session,
                    $window,
                    $session->for instanceof GroupTherapy ? $ward : null,
                ));

                $reminder->update([$column => now()]);
            });
        } catch (Throwable $exception) {
            Log::error('Failed to send a guardian video-consent reminder.', [
                'sessionId' => $session->id,
                'wardId' => $ward->id,
                'window' => $window,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    // Who still needs their guardians' consent for this session's video: a one-to-one therapy's minor client without a
    // valid grant; a group's minor members (its user creator and current members, never its counsellors) with no
    // grant of their own guardians' covering it.
    private function wardsNeedingConsent(Session $session): Collection
    {
        if ($session->for instanceof GroupTherapy) {
            $gate = EnsureVideoIsAvailableForSessionAction::new();
            $consent = HasValidVideoConsentForSessionAction::new();

            return GetTherapyClientsAction::new()->execute($session->for)
                ->filter(fn (User $member) => $gate->groupVideoNeedsGuardianConsent($session->for, $member) && ! $consent->forGroupMember($session, $member))
                ->values();
        }

        $ward = GetWardForVideoConsentableAction::new()->execute($session);

        return $ward && IsVideoConsentOutstandingForSessionAction::new()->execute($session) ? collect([$ward]) : collect();
    }
}
