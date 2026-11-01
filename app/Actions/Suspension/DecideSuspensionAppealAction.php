<?php

namespace App\Actions\Suspension;

use App\Actions\Action;
use App\Actions\Payout\ResumeHeldPayoutsAction;
use App\Enums\SuspensionAppealDecisionEnum;
use App\Exceptions\SuspensionException;
use App\Models\ReportStatusHistory;
use App\Models\Suspension;
use App\Models\User;
use App\Notifications\SuspensionAppealDecidedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

// SCRUM-359: a super administrator other than the one who applied the suspension (and not part of
// the report) upholds it or lifts it, with a note the person is sent.
class DecideSuspensionAppealAction extends Action
{
    public function execute(User $admin, ?Suspension $suspension, SuspensionAppealDecisionEnum $decision, string $note): Suspension
    {
        EnsureCanManageSuspensionAction::new()->execute($admin, $suspension);

        if ($suspension->created_by === $admin->id) {
            throw new SuspensionException('You applied this suspension, so another administrator has to decide the appeal.', 403);
        }

        $note = trim($note);
        if (mb_strlen($note) < 10) {
            throw new SuspensionException('Write a note for the person (at least 10 characters).', 422);
        }

        $suspension = DB::transaction(function () use ($admin, $suspension, $decision, $note) {
            $suspension = Suspension::query()->lockForUpdate()->findOrFail($suspension->id);

            if (! $suspension->appealIsPending()) {
                throw new SuspensionException('There is no appeal waiting on this suspension.', 422);
            }

            if ($decision == SuspensionAppealDecisionEnum::lifted) {
                $suspension = LiftSuspensionAction::new()->lift($admin, $suspension, $note, ReportStatusHistory::EVENT_APPEAL_DECIDED);
            }

            $suspension->forceFill([
                'appeal_decision' => $decision,
                'appeal_note' => $note,
                'appeal_decided_by' => $admin->id,
                'appeal_decided_at' => now(),
            ])->save();

            ReportStatusHistory::create([
                'report_id' => $suspension->report_id,
                'user_id' => $admin->id,
                'event' => ReportStatusHistory::EVENT_APPEAL_DECIDED,
                'note' => $note,
                'data' => ['suspension' => $suspension->id, 'decision' => $decision->value],
            ]);

            return $suspension;
        });

        Notification::send($suspension->user, new SuspensionAppealDecidedNotification($suspension));

        if ($decision == SuspensionAppealDecisionEnum::lifted) {
            rescue(fn () => ResumeHeldPayoutsAction::new()->execute($suspension->user), report: true);
        }

        return $suspension;
    }
}
