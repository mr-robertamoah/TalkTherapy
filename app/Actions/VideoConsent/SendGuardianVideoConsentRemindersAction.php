<?php

namespace App\Actions\VideoConsent;

use App\Actions\Action;
use App\Enums\SessionStatusEnum;
use App\Enums\VideoConsentReminderWindowEnum;
use App\Models\Session;
use App\Models\VideoConsentReminder;
use App\Notifications\GuardianVideoConsentReminderNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

// TT-3.1e-e/SCRUM-284: reminds a ward's guardians that video consent is still outstanding for an
// upcoming online therapy session, once per window:
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

        Session::query()
            ->whereTherapy()
            ->whereOnline()
            ->where('status', SessionStatusEnum::pending->value)
            ->where('start_time', '>', now())
            ->where('start_time', '<=', $upperBound)
            // Per session, while only one-to-one sessions are reminded (one ward each). SCRUM-313 PR 4 makes this per
            // ward when group sessions are, or a second minor would never be reminded.
            ->whereDoesntHave('videoConsentReminders', fn ($query) => $query->whereNotNull($column))
            ->get()
            ->each(function (Session $session) use ($window, $column) {
                try {
                    DB::transaction(function () use ($session, $window, $column) {
                        // Reviewer finding (2026-09-11): lockForUpdate() on a VideoConsentReminder
                        // row that doesn't exist yet (this session's very first reminder) locks
                        // nothing -- the exact bug class already found and fixed in
                        // GrantVideoConsentAction (see decision-log.md's SCRUM-281 entry). Locking
                        // the guaranteed-already-existing Session row first serializes two
                        // overlapping sweep runs correctly regardless of isolation level.
                        Session::query()->lockForUpdate()->find($session->id);

                        $ward = GetWardForVideoConsentableAction::new()->execute($session);

                        if (! $ward) {
                            return;
                        }

                        // SCRUM-313: per ward (a group will have several).
                        $reminder = VideoConsentReminder::query()->firstOrCreate(['session_id' => $session->id, 'ward_id' => $ward->id]);

                        if ($reminder->{$column}) {
                            return;
                        }

                        if (! IsVideoConsentOutstandingForSessionAction::new()->execute($session)) {
                            return;
                        }

                        $guardians = $ward->guardians()->with('guardian')->get()->pluck('guardian')->filter();

                        if ($guardians->isEmpty()) {
                            return;
                        }

                        Notification::send($guardians, new GuardianVideoConsentReminderNotification($session, $window));

                        $reminder->update([$column => now()]);
                    });
                } catch (Throwable $exception) {
                    Log::error('Failed to send a guardian video-consent reminder.', [
                        'sessionId' => $session->id,
                        'window' => $window,
                        'exception' => $exception->getMessage(),
                    ]);
                }
            });
    }
}
