<?php

namespace App\Actions\Payout;

use App\Actions\Action;
use App\Enums\CounsellorEarningStatusEnum;
use App\Enums\CounsellorPayoutStatusEnum;
use App\Models\CounsellorPayout;
use App\Models\User;
use App\Notifications\PayoutFailedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

// TT-7.6c/SCRUM-227: mirrors RecordTransactionStatusAction's role exactly -- the single choke
// point both ProcessCounsellorPayoutJob (the initial Paystack call's own synchronous response, if
// terminal) and the transfer.success/transfer.failed/transfer.reversed webhook call into, so
// idempotency lives once regardless of which arrives first or whether the webhook retries.
class RecordCounsellorPayoutStatusAction extends Action
{
    private const TERMINAL_STATUSES = [
        CounsellorPayoutStatusEnum::succeeded->value,
        CounsellorPayoutStatusEnum::failed->value,
    ];

    public function execute(CounsellorPayout $payout, string $status, string $source, ?string $message = null): CounsellorPayout
    {
        if ($payout->status === $status) {
            return $payout;
        }

        // Once succeeded or failed, a payout is terminal -- a later, differently-statused event
        // for the same reference (out-of-order webhook delivery, a stale replay) must never
        // regress it. Critically, this also means a `transfer.reversed` arriving after an
        // already-recorded `transfer.success` does NOT retroactively re-fail a payout whose
        // earnings have already been marked paid_out -- a reversal after the fact is a
        // reconciliation concern for a future ticket, not something this action silently
        // half-handles by flipping status without also undoing the paid_out marking.
        if (in_array($payout->status, self::TERMINAL_STATUSES, true)) {
            Log::warning('Ignored a counsellor payout status update that would have moved it away from a terminal state.', [
                'counsellor_payout_id' => $payout->id,
                'current_status' => $payout->status,
                'attempted_status' => $status,
                'source' => $source,
            ]);

            return $payout;
        }

        $payout = DB::transaction(function () use ($payout, $status, $source, $message) {
            $payout->update(['status' => $status]);

            $payout->statusHistories()->create([
                'status' => $status,
                'source' => $source,
                'message' => $message,
            ]);

            $payout = $payout->refresh();

            if ($status === CounsellorPayoutStatusEnum::succeeded->value) {
                $payout->earnings()->update(['status' => CounsellorEarningStatusEnum::paidOut->value]);
            }

            if ($status === CounsellorPayoutStatusEnum::failed->value) {
                // Money never silently disappears from the counsellor's available balance --
                // returned to `pending` so a future payout trigger can reclaim it. Left pointing
                // at this failed payout (counsellor_payout_id unchanged) until reclaimed, so this
                // failed batch's own history stays visible; a later successful claim reassigns it.
                $payout->earnings()->update(['status' => CounsellorEarningStatusEnum::pending->value]);
            }

            return $payout;
        });

        // Dispatched AFTER the transaction commits, not from inside it -- same reasoning as
        // TriggerCounsellorPayoutAction's job dispatch (this queue config's after_commit is
        // false, reviewer/security findings): a queued notification sent from inside an open
        // transaction could be picked up by a worker before the row it describes is actually
        // committed, and if Notification::send() itself ever threw, it would roll back the
        // status update/earnings-release for a reason that has nothing to do with them.
        if ($status === CounsellorPayoutStatusEnum::failed->value) {
            $this->notifyOfFailure($payout, $message);
        }

        return $payout;
    }

    private function notifyOfFailure(CounsellorPayout $payout, ?string $reason): void
    {
        $payout->counsellor->notify(new PayoutFailedNotification($payout, $reason));

        $admins = User::query()->whereAdmin()->inRandomOrder()->limit(2)->get();

        Notification::send($admins->unique(), new PayoutFailedNotification($payout, $reason));
    }
}
