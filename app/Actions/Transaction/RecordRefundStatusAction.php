<?php

namespace App\Actions\Transaction;

use App\Actions\Action;
use App\Enums\RefundStatusEnum;
use App\Models\Refund;
use App\Models\User;
use App\Notifications\RefundExecutionFailedNotification;
use App\Notifications\RefundFailedNotification;
use App\Notifications\RefundSucceededNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

// TT-7.7d/SCRUM-252: mirrors RecordCounsellorPayoutStatusAction's role exactly -- the single
// choke point both ProcessRefundJob (the initial Paystack call's own synchronous response, if
// terminal) and the refund.processed/refund.failed webhook call into, so idempotency lives once
// regardless of which arrives first or whether the webhook retries.
class RecordRefundStatusAction extends Action
{
    private const TERMINAL_STATUSES = [
        RefundStatusEnum::success->value,
        RefundStatusEnum::failed->value,
    ];

    public function execute(Refund $refund, string $status, string $source, ?string $message = null): Refund
    {
        // Security-engineer finding: the terminal/same-status checks used to run against the
        // caller's in-memory copy of $refund, with the actual write in a separate step -- two
        // near-simultaneous callers (ProcessRefundJob's own synchronous response racing a
        // refund.processed/refund.failed webhook, or two overlapping webhook deliveries) could
        // both load the refund while still pending/processing, both pass the guard, and both
        // commit, with no re-validation against what the other just wrote. Re-fetching with
        // lockForUpdate() INSIDE the transaction and re-running both checks against that freshly-
        // locked row closes the race: concurrent callers serialize on the lock, and the second one
        // re-checks against the first's already-committed status. Mirrors the exact
        // "lockForUpdate() alone isn't enough if the invariant check itself is a plain read" fix
        // already applied to EnsureTransactionIsRefundEligibleAction/RespondToRefundRequestAction.
        return DB::transaction(function () use ($refund, $status, $source, $message) {
            $refund = Refund::query()->lockForUpdate()->findOrFail($refund->id);

            if ($refund->status === $status) {
                return $refund;
            }

            // Once success or failed, a refund is terminal -- a later, differently-statused event
            // for the same transaction (out-of-order webhook delivery, a stale replay) must never
            // regress it.
            if (in_array($refund->status, self::TERMINAL_STATUSES, true)) {
                Log::warning('Ignored a refund status update that would have moved it away from a terminal state.', [
                    'refund_id' => $refund->id,
                    'current_status' => $refund->status,
                    'attempted_status' => $status,
                    'source' => $source,
                ]);

                return $refund;
            }

            $refund->update(['status' => $status]);

            $refund->statusHistories()->create([
                'status' => $status,
                'source' => $source,
                'message' => $message,
            ]);

            $refund = $refund->refresh();

            // Reviewer finding: this used to run AFTER the transaction above committed, on the
            // (wrong) assumption it needed the same "queue connection's after_commit is false"
            // treatment as a queued notification -- but ReconcileOrgFinancedRefundAction is a
            // plain synchronous Action, not a queued job, so that reasoning never actually applied
            // to it. Left outside the transaction, a transient failure inside it (its own
            // lockForUpdate() transaction deadlocking under contention, a momentary DB blip) would
            // permanently strand this transaction's CounsellorEarning row(s) in `pending` while
            // the Refund itself is already committed `success` forever, with no retry path (every
            // later caller's own terminal-status/active-refund-status guard would just no-op).
            // Nesting it here (Laravel nests via savepoints) means if it throws, the refund's own
            // status write rolls back with it, so a retry of THIS action can attempt both again.
            if ($status === RefundStatusEnum::success->value) {
                $transaction = $refund->transaction;

                // ReconcileOrgFinancedRefundAction (TT-7.3b-g/SCRUM-239) throws for a transaction
                // with no organization_id -- only call it for an org-financed one, exactly as its
                // own docblock anticipates this ticket doing.
                if ($transaction->organization_id) {
                    ReconcileOrgFinancedRefundAction::new()->execute($transaction);
                }

                // TT-7.7e/SCRUM-253: safe to call from inside this transaction despite being
                // queued -- see the identical comment on notifyAdminsOfFailure() below.
                $refund->requestedBy?->notify(new RefundSucceededNotification($refund));
            }

            if ($status === RefundStatusEnum::failed->value) {
                // Safe to call from inside this transaction despite being queued: Queueable's
                // afterCommit() (set in this notification's own constructor) defers the actual
                // dispatch until every open DB transaction on this connection -- including this
                // one -- has committed, regardless of where within it Notification::send() is
                // called.
                $this->notifyAdminsOfFailure($refund, $message);

                // TT-7.7e/SCRUM-253: the client-facing counterpart -- RefundExecutionFailedNotification
                // above stays admin-only (it's for a human to investigate/decide next steps; no
                // automatic retry or reclaim mechanism exists, mirroring this epic's own "does not
                // itself call Paystack a second time" scope boundary elsewhere).
                $refund->requestedBy?->notify(new RefundFailedNotification($refund));
            }

            return $refund;
        });
    }

    private function notifyAdminsOfFailure(Refund $refund, ?string $reason): void
    {
        $admins = User::query()->whereAdmin()->inRandomOrder()->limit(2)->get();

        if ($admins->isEmpty()) {
            Log::warning('Cannot notify of a failed refund execution -- no admins exist on the platform.', [
                'refund_id' => $refund->id,
            ]);

            return;
        }

        Notification::send($admins, new RefundExecutionFailedNotification($refund, $reason));
    }
}
