<?php

namespace App\Actions\Safety;

use App\Actions\Action;
use App\Enums\SafetyFlagStatusEnum;
use App\Exceptions\SafetyFlagException;
use App\Models\SafetyFlag;
use App\Models\SafetyFlagEvent;
use App\Models\User;
use App\Notifications\SafetyFlagWithdrawnNotification;
use Illuminate\Support\Facades\DB;

// SCRUM-32 (TT-4.3b): the counsellor who raised a safety flag withdraws it, with a reason (only the
// handling administrator reads it). Open or acknowledged; one conditional update, which stops the
// re-alert and frees "one open flag per client per therapy". The record and its history stay; the
// administrator handling it (or, if nobody had acknowledged it, the administrators who were alerted) is
// told -- without content -- and can still note and resolve it. Anyone else: the same 404 as a missing flag.
class WithdrawSafetyFlagAction extends Action
{
    public const NOT_POSSIBLE = 'It can\'t be withdrawn now -- it may already be resolved or withdrawn.';

    public function execute(?User $counsellorUser, ?SafetyFlag $flag, string $reason): SafetyFlag
    {
        $counsellor = $counsellorUser?->counsellor;
        if (! $flag || ! $counsellor || (int) $flag->counsellor_id !== (int) $counsellor->id) {
            throw new SafetyFlagException(EnsureCanFlagClientAction::NOT_FOUND, 404);
        }

        DB::transaction(function () use ($counsellorUser, $flag, $reason) {
            $withdrawn = SafetyFlag::query()->whereKey($flag->id)
                ->whereIn('status', [SafetyFlagStatusEnum::open->value, SafetyFlagStatusEnum::acknowledged->value])
                ->update([
                    'status' => SafetyFlagStatusEnum::withdrawn->value, 'withdrawn_at' => now(),
                    'withdrawn_reason' => SafetyFlag::encryptedValue('withdrawn_reason', trim($reason)),
                    'open_client_key' => null, 'updated_at' => now(),
                ]);

            if ($withdrawn === 0) {
                throw new SafetyFlagException(self::NOT_POSSIBLE, 409);
            }

            SafetyFlagEvent::query()->create(['safety_flag_id' => $flag->id, 'action' => SafetyFlagEvent::WITHDRAWN, 'actor_user_id' => $counsellorUser->id]);
        });

        $flag->refresh();
        $handler = $flag->handler;
        $urgency = $flag->urgency->label();
        if (! $flag->handler_user_id) {
            // Nobody had acknowledged it: the administrators who were alerted.
            $flag->alertUninvolved(new SafetyFlagWithdrawnNotification($flag->public_id, $urgency, SafetyFlagWithdrawnNotification::ALERTED), "safety flag {$flag->public_id} withdrawn");
        } elseif ($handler && ! $handler->trashed() && $handler->isAdmin() && ! $flag->involvesAdmin($handler)) {
            rescue(fn () => $handler->notify(new SafetyFlagWithdrawnNotification($flag->public_id, $urgency, SafetyFlagWithdrawnNotification::HANDLER)), report: true);
        } else {
            // The handler can't act on it any more (gone, no longer an administrator, or now involved):
            // the super administrators who aren't involved, to hand it on.
            $flag->alertUninvolved(new SafetyFlagWithdrawnNotification($flag->public_id, $urgency, SafetyFlagWithdrawnNotification::SUPERS), "safety flag {$flag->public_id} withdrawn; its handler can't act on it", superOnly: true);
        }
        $flag->broadcastChanged();

        return $flag;
    }
}
