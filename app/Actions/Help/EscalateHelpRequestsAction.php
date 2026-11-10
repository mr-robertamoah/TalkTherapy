<?php

namespace App\Actions\Help;

use App\Actions\Action;
use App\Actions\Admin\NotifyUninvolvedAdminsAction;
use App\Enums\HelpRequestStatusEnum;
use App\Models\HelpRequest;
use App\Models\User;
use App\Notifications\HelpRequestWaitingNotification;
use App\Support\HelpBroadcast;

// SCRUM-33 (TT-4.4b): a help request still waiting after config help.first_alert_seconds alerts every
// administrator who isn't involved; still waiting after help.second_alert_seconds, again. Each alert is
// claimed with a conditional update, so the delayed job and the sweep never send it twice. Returns how
// many alerts went out.
class EscalateHelpRequestsAction extends Action
{
    public function execute(?int $onlyId = null): int
    {
        $sent = 0;

        foreach ([['escalated_at', 'first_alert_seconds', false], ['escalated_again_at', 'second_alert_seconds', true]] as [$column, $setting, $again]) {
            HelpRequest::query()->whereStatus(HelpRequestStatusEnum::waiting)
                ->when($onlyId, fn ($query) => $query->whereKey($onlyId))
                ->whereNull($column)
                // The second only a while after the first (review), never straight after it.
                ->when($again, fn ($query) => $query->whereNotNull('escalated_at')
                    ->where('escalated_at', '<=', now()->subSeconds((int) config('help.second_alert_seconds') - (int) config('help.first_alert_seconds'))))
                // SCRUM-395: one the ring cap didn't ring alerts at once (the first alert; the second as usual).
                ->where(fn ($query) => $query->where('created_at', '<=', now()->subSeconds((int) config("help.{$setting}")))
                    ->when(! $again, fn ($query) => $query->orWhereNotNull('ring_skipped_at')))
                ->lazyById()
                ->each(function (HelpRequest $request) use ($column, $again, &$sent) {
                    // Both alerts overdue (the worker or scheduler was down): one alert, not two at once.
                    $alsoSecond = ! $again && $request->created_at->lte(now()->subSeconds((int) config('help.second_alert_seconds')));
                    $claimed = HelpRequest::query()->whereKey($request->id)
                        ->where('status', HelpRequestStatusEnum::waiting->value)->whereNull($column)
                        ->update([$column => now(), ...($alsoSecond ? ['escalated_again_at' => now()] : []), 'updated_at' => now()]);
                    if (! $claimed) {
                        return;
                    }

                    // SCRUM-32: the shared "tell the administrators who aren't involved" (logged as critical
                    // when there's nobody to tell).
                    NotifyUninvolvedAdminsAction::new()->execute(
                        fn (User $admin) => $request->involvesAdmin($admin),
                        new HelpRequestWaitingNotification($request->id, (int) floor($request->created_at->diffInMinutes(now())), $again),
                        about: "urgent help request #{$request->id} still waiting",
                    );
                    HelpBroadcast::queueChanged($request->id);
                    $sent++;
                });
        }

        return $sent;
    }
}
