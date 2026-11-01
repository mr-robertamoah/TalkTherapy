<?php

namespace App\Actions\Suspension;

use App\Actions\Action;
use App\Enums\SuspensionAppealDecisionEnum;
use App\Exceptions\SuspensionException;
use App\Models\ReportStatusHistory;
use App\Models\Suspension;
use App\Models\User;
use App\Notifications\SuspensionEndedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

// SCRUM-359: ends a suspension early, with a reason (kept on the suspension and in the report's
// history). Cancelled sessions and declined requests are not restored -- the person is simply able
// to act again. Also used by DecideSuspensionAppealAction when an appeal succeeds.
class LiftSuspensionAction extends Action
{
    public function execute(User $admin, ?Suspension $suspension, string $reason, string $event = ReportStatusHistory::EVENT_SUSPENSION_LIFTED): Suspension
    {
        EnsureCanManageSuspensionAction::new()->execute($admin, $suspension);

        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw new SuspensionException('Give a reason (at least 10 characters) for lifting the suspension.', 422);
        }

        $suspension = DB::transaction(fn () => $this->lift($admin, $suspension, $reason, $event));

        Notification::send($suspension->user, new SuspensionEndedNotification($suspension, SuspensionEndedNotification::LIFTED));

        return $suspension;
    }

    // Inside the caller's transaction.
    public function lift(User $admin, Suspension $suspension, string $reason, string $event): Suspension
    {
        $suspension = Suspension::query()->lockForUpdate()->findOrFail($suspension->id);

        if (! $suspension->isActive()) {
            throw new SuspensionException('This suspension has already ended.', 422);
        }

        $appealWaiting = $suspension->appealIsPending();

        $suspension->forceFill([
            'lifted_at' => now(),
            'lifted_by' => $admin->id,
            'lift_reason' => $reason,
        ])->save();

        // Lifted with an appeal waiting: that answers the appeal too.
        if ($appealWaiting && $event == ReportStatusHistory::EVENT_SUSPENSION_LIFTED) {
            $suspension->forceFill([
                'appeal_decision' => SuspensionAppealDecisionEnum::lifted,
                'appeal_note' => $reason,
                'appeal_decided_by' => $admin->id,
                'appeal_decided_at' => now(),
            ])->save();
        }

        if ($event == ReportStatusHistory::EVENT_SUSPENSION_LIFTED) {
            ReportStatusHistory::create([
                'report_id' => $suspension->report_id,
                'user_id' => $admin->id,
                'event' => $event,
                'note' => $reason,
                'data' => ['suspension' => $suspension->id],
            ]);
        }

        return $suspension;
    }
}
