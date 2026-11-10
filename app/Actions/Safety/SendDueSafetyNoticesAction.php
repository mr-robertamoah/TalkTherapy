<?php

namespace App\Actions\Safety;

use App\Actions\Action;
use App\Enums\SafetyFlagNoticeStateEnum;
use App\Enums\SafetyFlagUrgencyEnum;
use App\Models\SafetyFlag;
use Throwable;

// SCRUM-32 (TT-4.3c, the user's decisions): notices still pending go out on their own at the flag's
// re-alert time (1h / 4h / 24h by urgency) -- counted from when it was raised while nobody has acknowledged
// it, and from when it was acknowledged once someone has (the handler can send or hold them before then).
// Held notices wait for the handler; a flag withdrawn after acknowledgement waits for them too; one
// withdrawn before had its notices cancelled. Every five minutes; each notice is claimed with the same
// "due" rule in its UPDATE (SafetyFlag::scopeWhereNoticesDue), so one the handler holds -- or a flag withdrawn
// -- a moment before is never sent (reviews). One flag failing never stops the rest. Returns how many went.
class SendDueSafetyNoticesAction extends Action
{
    public function execute(): int
    {
        if (! config('safety.enabled')) {
            return 0;
        }

        $sent = 0;
        foreach (SafetyFlagUrgencyEnum::cases() as $urgency) {
            SafetyFlag::query()->whereNoticesDue($urgency)
                ->where(fn ($query) => $query
                    ->where('client_notice_state', SafetyFlagNoticeStateEnum::pending->value)
                    ->orWhere('guardian_notice_state', SafetyFlagNoticeStateEnum::pending->value))
                ->lazyById()
                ->each(function (SafetyFlag $flag) use ($urgency, &$sent) {
                    try {
                        $went = 0;
                        foreach ([SendSafetyFlagNoticeAction::CLIENT, SendSafetyFlagNoticeAction::GUARDIANS] as $which) {
                            $outcome = SendSafetyFlagNoticeAction::new()->execute($flag, $which, constrain: fn ($query) => $query->whereNoticesDue($urgency));
                            $went += (int) in_array($outcome, [SendSafetyFlagNoticeAction::SENT, SendSafetyFlagNoticeAction::NO_RECIPIENT], true);
                        }
                        if ($went) {
                            $sent += $went;
                            $flag->broadcastChanged();
                        }
                    } catch (Throwable $th) {
                        report($th);
                    }
                });
        }

        return $sent;
    }
}
