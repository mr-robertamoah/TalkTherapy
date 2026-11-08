<?php

namespace App\Actions\Safety;

use App\Actions\Action;
use App\Enums\SafetyFlagStatusEnum;
use App\Exceptions\SafetyFlagException;
use App\Models\SafetyFlag;
use App\Models\SafetyFlagEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// SCRUM-32: an administrator (not involved) takes an open safety flag and becomes the one handling it.
// The first wins: one conditional update, so two at once can't both.
class AcknowledgeSafetyFlagAction extends Action
{
    public const TAKEN = 'Another administrator has already acknowledged it, or it is no longer open.';

    public function execute(?User $admin, ?SafetyFlag $flag): SafetyFlag
    {
        $flag = EnsureCanHandleSafetyFlagAction::new()->execute($admin, $flag);

        DB::transaction(function () use ($admin, $flag) {
            $won = SafetyFlag::query()->whereKey($flag->id)
                ->where('status', SafetyFlagStatusEnum::open->value)->whereNull('handler_user_id')
                ->update(['status' => SafetyFlagStatusEnum::acknowledged->value, 'handler_user_id' => $admin->id, 'acknowledged_at' => now(), 'updated_at' => now()]);

            if ($won === 0) {
                throw new SafetyFlagException(self::TAKEN, 409);
            }

            SafetyFlagEvent::query()->create(['safety_flag_id' => $flag->id, 'action' => SafetyFlagEvent::ACKNOWLEDGED, 'actor_user_id' => $admin->id]);
        });

        $flag->refresh()->broadcastChanged();

        return $flag;
    }
}
