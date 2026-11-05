<?php

namespace App\Services;

use App\Actions\Guardianship\AnnounceGuardianAccessChangeAction;
use App\Actions\Guardianship\GetWardOpenTherapiesAction;
use App\Actions\Guardianship\HandleGuardianLossAction;
use App\Actions\Guardianship\HandleGuardianRestoredAction;
use App\Actions\SafeguardingException\EnsureCanManageSafeguardingExceptionAction;
use App\Models\SafeguardingException;
use App\Models\User;
use App\Notifications\SafeguardingExceptionEndingSoonNotification;
use App\Notifications\TherapyContinuationNotification;
use App\Support\BestEffort;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

// SCRUM-381: the two sweeps of safeguarding exceptions. Neither decides whether one is in force --
// that is worked out from its timestamps on every request (SafeguardingException::scopeActive), so
// a missed run can't make one last longer. Each row is claimed under its locks (the minor's row,
// then the exception), so two overlapping runs can't both act on it; one failing is logged and
// doesn't stop the rest (copied from SuspensionService).
class SafeguardingExceptionService extends Service
{
    // Records that each exception which ran out has ended, and -- when that left the minor paused
    // (still no guardian) -- runs the usual loss handling once (HandleGuardianLossAction::schedule).
    public function endExpiredExceptions(): int
    {
        $ended = 0;

        SafeguardingException::query()
            ->whereNull('ended_at')
            ->whereNull('end_processed_at')
            ->where('ends_at', '<=', now())
            ->pluck('id')
            ->each(function (int $id) use (&$ended) {
                try {
                    DB::transaction(function () use ($id, &$ended) {
                        [$exception, $ward] = SafeguardingException::lockWithWard($id);

                        if (! $exception || $exception->ended_at || $exception->end_processed_at || $exception->ends_at->isFuture()) {
                            return;
                        }

                        $exception->forceFill(['end_processed_at' => now()])->save();
                        $ended++;

                        if ($ward?->isPausedForMissingGuardian(fresh: true)) {
                            HandleGuardianLossAction::new()->schedule($ward->id, HandleGuardianLossAction::SAFEGUARDING_EXCEPTION_EXPIRED);
                        }
                    });
                } catch (\Throwable $th) {
                    Log::error('Could not end an expired safeguarding exception.', ['exception' => $id, 'error' => $th->getMessage()]);
                }
            });

        return $ended;
    }

    // The heads-up, once per exception, in the last REMINDER_DAYS before it runs out: to the minor,
    // their counsellors (1:1 and group) and the super administrators. No reason anywhere. Skipped
    // (but still claimed) when the minor has a guardian again by then.
    public function sendExpiryReminders(): int
    {
        $sent = 0;

        SafeguardingException::query()
            ->active()
            ->whereNull('expiry_reminded_at')
            ->where('ends_at', '<=', now()->addDays(SafeguardingException::REMINDER_DAYS))
            ->pluck('id')
            ->each(function (int $id) use (&$sent) {
                try {
                    [$exception, $ward] = DB::transaction(function () use ($id) {
                        [$exception, $ward] = SafeguardingException::lockWithWard($id);

                        if (! $exception || $exception->expiry_reminded_at || ! $exception->isActive()) {
                            return [null, null];
                        }

                        $exception->forceFill(['expiry_reminded_at' => now()])->save();

                        $needed = $ward?->lacksGuardianCover(fresh: true);

                        return [$exception, $needed ? $ward : null];
                    });

                    if ($exception && $ward) {
                        $this->remind($exception, $ward);
                        $sent++;
                    }
                } catch (\Throwable $th) {
                    Log::error('Could not send a safeguarding exception reminder.', ['exception' => $id, 'error' => $th->getMessage()]);
                }
            });

        return $sent;
    }

    private function remind(SafeguardingException $exception, User $ward): void
    {
        $context = ['exception' => $exception->id];

        BestEffort::attempt(function () use ($exception, $ward) {
            $open = HandleGuardianRestoredAction::new()->carryingOn($ward);

            if (GetWardOpenTherapiesAction::new()->hasAny($open)) {
                AnnounceGuardianAccessChangeAction::new()->continuation($ward, $open, TherapyContinuationNotification::ENDING_SOON, $exception->ends_at);
            }
        }, 'safeguarding-exception-reminder', $context);

        BestEffort::attempt(
            fn () => Notification::send(EnsureCanManageSafeguardingExceptionAction::new()->deciders($ward), new SafeguardingExceptionEndingSoonNotification($exception)),
            'safeguarding-exception-reminder-admins',
            $context,
        );
    }
}
