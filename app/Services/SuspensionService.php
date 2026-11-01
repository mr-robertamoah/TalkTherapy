<?php

namespace App\Services;

use App\Actions\Payout\ResumeHeldPayoutsAction;
use App\Models\ReportStatusHistory;
use App\Models\Suspension;
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
                        $suspension = Suspension::query()->lockForUpdate()->find($id);

                        if (! $suspension || $suspension->end_processed_at || $suspension->lifted_at) {
                            return null;
                        }

                        $suspension->forceFill(['end_processed_at' => now()])->save();

                        ReportStatusHistory::create([
                            'report_id' => $suspension->report_id,
                            'user_id' => null,
                            'event' => ReportStatusHistory::EVENT_SUSPENSION_ENDED,
                            'data' => ['suspension' => $suspension->id],
                        ]);

                        return $suspension;
                    });

                    if ($suspension) {
                        Notification::send($suspension->user, new SuspensionEndedNotification($suspension, SuspensionEndedNotification::EXPIRED));
                        ResumeHeldPayoutsAction::new()->execute($suspension->user);
                        $ended++;
                    }
                } catch (\Throwable $th) {
                    Log::error('Could not end an expired suspension.', ['suspension' => $id, 'error' => $th->getMessage()]);
                }
            });

        return $ended;
    }
}
