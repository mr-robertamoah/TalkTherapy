<?php

namespace App\Actions\Safety;

use App\Actions\Action;
use App\Enums\SafetyFlagNoticeStateEnum;
use App\Exceptions\SafetyFlagException;
use App\Models\SafetyFlag;
use App\Models\SafetyFlagEvent;
use App\Models\SafetyFlagNote;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// SCRUM-32 (TT-4.3c, the user's decisions): the administrator handling a safety flag decides each notice
// -- to the client, or to a minor's guardians:
// - send it now;
// - hold it, for a recorded safety reason (it then waits for them -- never sent automatically);
// - withhold it for good, with a reason.
// For guardians their decision is final, whatever the counsellor asked (a counsellor's safeguarding reason
// arrives as "held"). Each reason is kept as its own encrypted note, with its author -- never overwriting
// another (reviews) -- and each decision is in the history (never its reason). Only while they handle it.
class DecideSafetyFlagNoticeAction extends Action
{
    public const SEND = 'send';

    public const HOLD = 'hold';

    public const WITHHOLD = 'withhold';

    public const NOT_POSSIBLE = 'That notice can\'t be changed now -- it may have been sent or decided already.';

    // The reason note just written (null for a send) -- returned so the page shows it without reading the rest.
    public ?SafetyFlagNote $note = null;

    public function execute(?User $admin, ?SafetyFlag $flag, string $which, string $decision, ?string $reason = null): SafetyFlag
    {
        $flag = EnsureCanHandleSafetyFlagAction::new()->execute($admin, $flag, handler: true);
        if (! $flag->isBeingHandled()) {
            throw new SafetyFlagException(AddSafetyFlagNoteAction::NOT_HANDLED, 409);
        }

        $guardians = $which === SendSafetyFlagNoticeAction::GUARDIANS;
        $column = $guardians ? 'guardian_notice_state' : 'client_notice_state';

        if ($decision === self::SEND) {
            // Sent -- or settled with nobody to send it to (the admin row then says so).
            $outcome = SendSafetyFlagNoticeAction::new()->execute($flag, $which, $admin);
            if ($outcome === SendSafetyFlagNoticeAction::NOT_CLAIMED) {
                throw new SafetyFlagException(self::NOT_POSSIBLE, 409);
            }
            if ($outcome === SendSafetyFlagNoticeAction::FAILED) {
                throw new SafetyFlagException('It couldn\'t be sent just now. Please try again.', 503);
            }
            if ($guardians) {
                SafetyFlag::query()->whereKey($flag->id)->update(['guardian_decided_by_id' => $admin->id]);
            }
        } else {
            if (! trim((string) $reason)) {
                throw new SafetyFlagException('Please give the reason.', 422);
            }

            DB::transaction(function () use ($admin, $flag, $guardians, $column, $decision, $reason) {
                $to = $decision === self::HOLD ? SafetyFlagNoticeStateEnum::held : SafetyFlagNoticeStateEnum::withheld;
                $from = $decision === self::HOLD ? [SafetyFlagNoticeStateEnum::pending->value] : SafetyFlagNoticeStateEnum::unsettledValues();

                $changed = SafetyFlag::query()->whereKey($flag->id)->whereIn($column, $from)->update([
                    $column => $to->value,
                    ...($guardians ? ['guardian_decided_by_id' => $admin->id] : []),
                    'updated_at' => now(),
                ]);
                if (! $changed) {
                    throw new SafetyFlagException(self::NOT_POSSIBLE, 409);
                }

                $note = $this->note = SafetyFlagNote::query()->create([
                    'safety_flag_id' => $flag->id, 'author_user_id' => $admin->id,
                    'kind' => SafetyFlagNote::noticeKind($guardians, $decision === self::HOLD),
                    'content' => trim($reason),
                ]);

                SafetyFlagEvent::query()->create([
                    'safety_flag_id' => $flag->id, 'actor_user_id' => $admin->id, 'data' => ['noteId' => $note->id],
                    'action' => match (true) {
                        $guardians && $decision === self::HOLD => SafetyFlagEvent::GUARDIAN_NOTICE_HELD,
                        $guardians => SafetyFlagEvent::GUARDIAN_NOTICE_WITHHELD,
                        $decision === self::HOLD => SafetyFlagEvent::CLIENT_NOTICE_HELD,
                        default => SafetyFlagEvent::CLIENT_NOTICE_WITHHELD,
                    },
                ]);
            });
        }

        $flag->refresh()->broadcastChanged();

        return $flag;
    }
}
