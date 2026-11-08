<?php

namespace App\Actions\Safety;

use App\Actions\Action;
use App\Enums\SafetyFlagStatusEnum;
use App\Exceptions\SafetyFlagException;
use App\Models\SafetyFlag;
use App\Models\SafetyFlagEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// SCRUM-32: a super administrator (not involved) hands an acknowledged safety flag to another
// administrator who isn't involved either -- recorded in its history.
class ReassignSafetyFlagAction extends Action
{
    public const SUPER_ONLY = 'Only a super administrator can hand a safety alert to someone else.';

    public const NOT_POSSIBLE = 'It can\'t be handed to that administrator.';

    public function execute(?User $superAdmin, ?SafetyFlag $flag, ?User $to): SafetyFlag
    {
        $flag = EnsureCanHandleSafetyFlagAction::new()->execute($superAdmin, $flag);
        if (! $superAdmin->isSuperAdmin()) {
            throw new SafetyFlagException(self::SUPER_ONLY, 403);
        }

        if (! $to || ! $to->isAdmin() || $to->isAccountSuspended() || $flag->involvesAdmin($to) || $flag->isHandledBy($to)) {
            throw new SafetyFlagException(self::NOT_POSSIBLE, 422);
        }

        DB::transaction(function () use ($superAdmin, $flag, $to) {
            $moved = SafetyFlag::query()->whereKey($flag->id)->where('status', SafetyFlagStatusEnum::acknowledged->value)
                ->where('handler_user_id', $flag->handler_user_id)
                ->update(['handler_user_id' => $to->id, 'updated_at' => now()]);

            if ($moved === 0) {
                throw new SafetyFlagException(self::NOT_POSSIBLE, 409);
            }

            SafetyFlagEvent::query()->create([
                'safety_flag_id' => $flag->id, 'action' => SafetyFlagEvent::REASSIGNED, 'actor_user_id' => $superAdmin->id,
                'data' => ['from' => $flag->handler_user_id, 'to' => $to->id],
            ]);
        });

        $flag->refresh()->broadcastChanged();

        return $flag;
    }
}
