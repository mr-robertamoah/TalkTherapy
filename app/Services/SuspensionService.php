<?php

namespace App\Services;

use App\Actions\Guardianship\HandleGuardianRestoredAction;
use App\Actions\Payout\ResumeHeldPayoutsAction;
use App\Enums\SuspensionScopeEnum;
use App\Models\Guardianship;
use App\Models\ReportStatusHistory;
use App\Models\Suspension;
use App\Models\User;
use App\Notifications\SuspensionEndedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

// SCRUM-359
class SuspensionService extends Service
{
    // A suspension stops applying the moment its end passes (Suspension::scopeActive) -- this sweep
    // only records that it ended and tells the person. Each one is claimed under a lock, so two
    // overlapping runs can't both send the notice; one failing is logged and doesn't stop the rest.
    public function endExpiredSuspensions(): int
    {
        $ended = 0;

        Suspension::query()
            ->whereNull('lifted_at')
            ->whereNull('end_processed_at')
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->pluck('id')
            ->each(function (int $id) use (&$ended) {
                try {
                    $suspension = DB::transaction(function () use ($id) {
                        // SCRUM-382: the person, then the suspension (Suspension::lockWithPerson(),
                        // which throws if the row is gone -- suspensions are never deleted).
                        $suspension = Suspension::lockWithPerson($id);

                        if ($suspension->end_processed_at || $suspension->lifted_at) {
                            return null;
                        }

                        $suspension->forceFill(['end_processed_at' => now()])->save();

                        // SCRUM-288 (Q3): access already came back when it ran out (the live check);
                        // this tells the wards it paused.
                        $this->scheduleWardsResumed($suspension);

                        ReportStatusHistory::create([
                            'report_id' => $suspension->report_id,
                            'user_id' => null,
                            'event' => ReportStatusHistory::EVENT_SUSPENSION_ENDED,
                            'data' => ['suspension' => $suspension->id],
                        ]);

                        return $suspension;
                    });

                    if ($suspension) {
                        Notification::send($suspension->user, new SuspensionEndedNotification(
                            $suspension,
                            SuspensionEndedNotification::EXPIRED,
                            (bool) $suspension->user?->isPausedForMissingGuardian(fresh: true),
                        ));
                        ResumeHeldPayoutsAction::new()->execute($suspension->user);
                        $ended++;
                    }
                } catch (\Throwable $th) {
                    Log::error('Could not end an expired suspension.', ['suspension' => $id, 'error' => $th->getMessage()]);
                }
            });

        return $ended;
    }

    // A ward was paused by this suspension when, leaving out this guardian and any other whose
    // account suspension has also run out but not been processed yet (they were not counting
    // either), they would be paused. Read with locking reads under the wards' locks (the one lock
    // order, Guardianship::lockedWardsOf()); told after the commit.
    private function scheduleWardsResumed(Suspension $suspension): void
    {
        $guardian = $suspension->user;

        if ($suspension->scope != SuspensionScopeEnum::account || ! $guardian) {
            return;
        }

        $justEnded = Suspension::query()
            ->where('scope', SuspensionScopeEnum::account->value)
            ->whereNull('lifted_at')
            ->whereNull('end_processed_at')
            ->where('ends_at', '<=', now())
            ->sharedLock()
            ->pluck('user_id')
            ->push($guardian->id)
            ->unique()
            ->all();

        $restored = HandleGuardianRestoredAction::new();

        Guardianship::lockedWardsOf($guardian)
            ->filter(fn (User $ward) => $ward->isPausedForMissingGuardian(exceptGuardianIds: $justEnded))
            ->each(fn (User $ward) => DB::afterCommit(fn () => $restored->execute($ward, true, HandleGuardianRestoredAction::SUSPENSION_ENDED)));
    }
}
