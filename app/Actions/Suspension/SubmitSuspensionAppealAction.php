<?php

namespace App\Actions\Suspension;

use App\Actions\Action;
use App\Exceptions\SuspensionException;
use App\Models\ReportStatusHistory;
use App\Models\Suspension;
use App\Models\User;
use App\Notifications\SuspensionAppealSubmittedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

// SCRUM-359: the suspended person's one written appeal, while the suspension is in force. Super
// administrators who could decide it (not the one who applied it, not part of the report) are told.
class SubmitSuspensionAppealAction extends Action
{
    public const NOT_FOUND = 'Suspension not found.';

    public function execute(User $user, ?Suspension $suspension, string $text): Suspension
    {
        if (! $suspension || $suspension->user_id !== $user->id) {
            throw new SuspensionException(self::NOT_FOUND, 404);
        }

        $text = trim($text);
        if (mb_strlen($text) < 20) {
            throw new SuspensionException('Explain your appeal in at least 20 characters.', 422);
        }

        $suspension = DB::transaction(function () use ($user, $suspension, $text) {
            $suspension = Suspension::query()->lockForUpdate()->findOrFail($suspension->id);

            if (! $suspension->canBeAppealed()) {
                throw new SuspensionException($suspension->appealed_at ? 'You have already appealed this suspension.' : 'This suspension has already ended.', 422);
            }

            $suspension->forceFill(['appeal_text' => $text, 'appealed_at' => now()])->save();

            ReportStatusHistory::create([
                'report_id' => $suspension->report_id,
                'user_id' => $user->id,
                'event' => ReportStatusHistory::EVENT_APPEAL_SUBMITTED,
                'data' => ['suspension' => $suspension->id],
            ]);

            return $suspension;
        });

        $deciders = User::query()
            ->whereHas('administrator', fn ($q) => $q->whereSuperAdmin())
            ->whereKeyNot([$suspension->created_by, $user->id])
            ->get()
            ->reject(fn (User $admin) => $suspension->involves($admin) || $admin->isAccountSuspended());

        Notification::send($deciders, new SuspensionAppealSubmittedNotification($suspension));

        return $suspension;
    }
}
