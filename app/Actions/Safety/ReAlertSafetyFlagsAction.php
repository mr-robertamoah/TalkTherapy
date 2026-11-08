<?php

namespace App\Actions\Safety;

use App\Actions\Action;
use App\Enums\SafetyFlagStatusEnum;
use App\Enums\SafetyFlagUrgencyEnum;
use App\Models\SafetyFlag;
use App\Models\SafetyFlagEvent;
use App\Notifications\SafetyFlagReAlertNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

// SCRUM-32 (TT-4.3b): a safety flag nobody has acknowledged by its re-alert time (1h / 4h / 24h by
// urgency, SafetyFlag::realertMinutes) alerts the super administrators who aren't involved -- once.
// Claimed with a conditional update, so the delayed job and the sweep never send it twice (the same
// pattern as EscalateHelpRequestsAction); if sending fails the claim is released for the next sweep.
// Nobody to tell: the fallback mailbox and a critical log. A missing or invalid fallback mailbox while the
// feature is on is logged as critical once a day (devops review). Returns how many flags were re-alerted.
class ReAlertSafetyFlagsAction extends Action
{
    public function execute(?int $onlyId = null): int
    {
        if (! config('safety.enabled')) {
            return 0;
        }

        if (! SafetyFlag::fallbackMailbox() && Cache::add('safety-flags:fallback-mailbox-warned', true, now()->addDay())) {
            Log::critical('Safety flags are on, but SAFETY_FALLBACK_MAILBOX is missing or not a valid address: an alert nobody can receive would go nowhere.');
        }

        $sent = 0;

        foreach (SafetyFlagUrgencyEnum::cases() as $urgency) {
            SafetyFlag::query()
                ->where('status', SafetyFlagStatusEnum::open->value)
                ->where('urgency', $urgency->value)
                ->whereNull('realerted_at')
                ->where('created_at', '<=', now()->subMinutes(SafetyFlag::realertMinutes($urgency)))
                ->when($onlyId, fn ($query) => $query->whereKey($onlyId))
                ->lazyById()
                ->each(function (SafetyFlag $flag) use ($urgency, &$sent) {
                    $claimed = SafetyFlag::query()->whereKey($flag->id)
                        ->where('status', SafetyFlagStatusEnum::open->value)->whereNull('realerted_at')
                        ->update(['realerted_at' => now(), 'updated_at' => now()]);
                    if (! $claimed) {
                        return;
                    }

                    $result = $flag->alertUninvolved(
                        new SafetyFlagReAlertNotification($flag->id, $flag->public_id, $urgency->label(), (int) floor($flag->created_at->diffInHours(now()))),
                        "safety flag {$flag->public_id} still unacknowledged",
                        superOnly: true,
                    );

                    // Sending failed: release the claim, so the next sweep tries again (reviews: a safety
                    // re-alert is at least once, never silently lost).
                    if ($result->failed) {
                        SafetyFlag::query()->whereKey($flag->id)->update(['realerted_at' => null]);

                        return;
                    }
                    if ($result->told > 0) {
                        SafetyFlagEvent::query()->create(['safety_flag_id' => $flag->id, 'action' => SafetyFlagEvent::REALERTED]);
                    }
                    $sent++;
                });
        }

        return $sent;
    }
}
