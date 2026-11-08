<?php

namespace App\Actions\Safety;

use App\Actions\Action;
use App\Enums\SafetyFlagNoticeStateEnum;
use App\Enums\SafetyFlagOutcomeEnum;
use App\Enums\SafetyFlagStatusEnum;
use App\Exceptions\SafetyFlagException;
use App\Models\SafetyFlag;
use App\Models\SafetyFlagEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// SCRUM-32 (TT-4.3b): the administrator handling a safety flag resolves it with an outcome and a note
// (required). Acknowledged or withdrawn ones; one conditional update, which also frees "one open flag
// per client per therapy" (open_client_key). Never deleted.
class ResolveSafetyFlagAction extends Action
{
    public const NOT_POSSIBLE = 'It can\'t be resolved now -- it may have been resolved or handed on already.';

    public const NOTICES_UNSETTLED = 'Send or withhold the notices first -- a notice still waiting, or held, has to be decided before it is resolved.';

    public function execute(?User $admin, ?SafetyFlag $flag, SafetyFlagOutcomeEnum $outcome, string $note): SafetyFlag
    {
        $flag = EnsureCanHandleSafetyFlagAction::new()->execute($admin, $flag, handler: true);
        // TT-4.3c (the user's default): a held notice must be sent, or withheld with a reason, first.
        if ($flag->client_notice_state->isUnsettled() || $flag->guardian_notice_state->isUnsettled()) {
            throw new SafetyFlagException(self::NOTICES_UNSETTLED, 409);
        }

        DB::transaction(function () use ($admin, $flag, $outcome, $note) {
            $resolved = SafetyFlag::query()->whereKey($flag->id)->where('handler_user_id', $admin->id)
                ->whereIn('status', SafetyFlag::beingHandledStatuses())
                ->whereNotIn('client_notice_state', SafetyFlagNoticeStateEnum::unsettledValues())
                ->whereNotIn('guardian_notice_state', SafetyFlagNoticeStateEnum::unsettledValues())
                ->update([
                    'status' => SafetyFlagStatusEnum::resolved->value, 'resolved_at' => now(), 'outcome' => $outcome->value,
                    'outcome_note' => SafetyFlag::encryptedValue('outcome_note', trim($note)),
                    'open_client_key' => null, 'updated_at' => now(),
                ]);

            if ($resolved === 0) {
                throw new SafetyFlagException(self::NOT_POSSIBLE, 409);
            }

            SafetyFlagEvent::query()->create([
                'safety_flag_id' => $flag->id, 'action' => SafetyFlagEvent::RESOLVED, 'actor_user_id' => $admin->id,
                'data' => ['outcome' => $outcome->value],
            ]);
        });

        $flag->refresh()->broadcastChanged();

        return $flag;
    }
}
