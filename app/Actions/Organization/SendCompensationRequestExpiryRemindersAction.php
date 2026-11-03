<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Models\Request;
use App\Notifications\OrganizationCounsellorCompensationChangeExpiryReminderNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

// SCRUM-149 (TT-6.4c): sent once, ~2 days before a pending compensation-change request's
// expires_at -- reminder_sent_at (not day-arithmetic alone) makes this exactly-once
// regardless of how many times or how imprecisely this daily sweep actually runs.
//
// Security review (PR #87, post-merge): each row is re-locked and re-checked for `pending`
// immediately before writing, mirroring RespondToOrganizationCounsellorCompensationRequestAction
// /CounterOfferOrganizationCounsellorCompensationChangeAction -- without this, a concurrent
// accept/reject/counter-offer landing between this sweep's initial batch SELECT and its
// per-row UPDATE could be silently clobbered. Each row is also isolated in its own try/catch
// so one bad row (e.g. an unresolvable `to`) can't abort every other pending negotiation's
// reminder for the day.
// SCRUM-376: moved out of AppService.
class SendCompensationRequestExpiryRemindersAction extends Action
{
    public function execute(): void
    {
        Request::query()
            ->whereType(RequestTypeEnum::organizationCounsellorCompensationChange->value)
            ->wherePending()
            ->whereNull('reminder_sent_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addDays(2))
            ->get()
            ->each(function (Request $request) {
                try {
                    DB::transaction(function () use ($request) {
                        $locked = Request::query()->lockForUpdate()->findOrFail($request->id);

                        if ($locked->status !== RequestStatusEnum::pending->value || $locked->reminder_sent_at) {
                            return;
                        }

                        // A same-day "2 days before" reminder for an offer whose whole window
                        // was under 3 days adds noise, not value -- and guards against a
                        // malformed expires_at <= created_at pair ever reaching Carbon's
                        // absolute-value diffInDays(), which can't distinguish "3 days" from
                        // "-3 days".
                        if ($locked->expires_at->lessThanOrEqualTo($locked->created_at)
                            || $locked->created_at->diffInDays($locked->expires_at) < 3) {
                            return;
                        }

                        NotifyCompensationRequestRecipientAction::new()->execute(
                            $locked,
                            new OrganizationCounsellorCompensationChangeExpiryReminderNotification($locked)
                        );

                        $locked->update(['reminder_sent_at' => now()]);
                    });
                } catch (Throwable $exception) {
                    Log::error('Failed to send a compensation-change expiry reminder.', [
                        'requestId' => $request->id,
                        'exception' => $exception->getMessage(),
                    ]);
                }
            });
    }
}
