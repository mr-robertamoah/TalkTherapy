<?php

namespace App\Actions\Transaction;

use App\Actions\Action;
use App\Actions\Organization\CaptureOrganizationPaymentInstrumentAction;
use App\Enums\TransactionStatusEnum;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RecordTransactionStatusAction extends Action
{
    private const TERMINAL_STATUSES = [
        TransactionStatusEnum::success->value,
        TransactionStatusEnum::failed->value,
    ];

    // The single choke point both the webhook and the verify-callback fallback call into, so
    // idempotency lives once: Paystack retries webhook delivery, and the verify-callback can
    // race the webhook for the same reference. $gatewayData, when given, is Paystack's own
    // 'data' object from that same response/payload -- TT-7.3b-a/SCRUM-231 needs its
    // 'authorization' object to capture an organization's payment instrument, but this action
    // stays domain-agnostic (a no-op for every other caller, which simply omits it).
    public function execute(Transaction $transaction, string $status, string $source, ?string $message = null, ?array $gatewayData = null): Transaction
    {
        if ($transaction->status === $status) {
            return $transaction;
        }

        // Once success or failed, a transaction is terminal -- a later, differently-statused
        // event for the same reference (out-of-order delivery, a stale replay, or the webhook
        // and verify-callback racing each other) must never regress it. This isn't just about
        // not losing a good record: EnsureCanInitiateChargeAction's "already paid" guard
        // depends on `success` staying true once recorded, so flipping it back to `failed`
        // would reopen the therapy/session to being charged a second time.
        if (in_array($transaction->status, self::TERMINAL_STATUSES, true)) {
            Log::warning('Ignored a transaction status update that would have moved it away from a terminal state.', [
                'transaction_id' => $transaction->id,
                'current_status' => $transaction->status,
                'attempted_status' => $status,
                'source' => $source,
            ]);

            return $transaction;
        }

        // TT-7.6b/SCRUM-226 (reviewer finding): the status update and earnings generation below
        // must commit together or not at all. Without this wrapping, a failure inside
        // GenerateCounsellorEarningsAction after the status update had already committed would
        // leave the transaction permanently stuck as `success` with no CounsellorEarning row and
        // no path back through this action to retry -- the terminal-status guard above means a
        // later, identically-successful webhook/verify-callback replay would just short-circuit
        // on the first `if` and never reach the earnings call again. Rolling the status update
        // back too (by throwing out of this DB::transaction()) keeps the transaction in a
        // genuinely retriable non-terminal state instead.
        return DB::transaction(function () use ($transaction, $status, $source, $message, $gatewayData) {
            $transaction->update(['status' => $status]);

            $transaction->statusHistories()->create([
                'status' => $status,
                'source' => $source,
                'message' => $message,
            ]);

            $transaction = $transaction->refresh();

            // This is the ONE place a transaction actually transitions to SUCCESS (the
            // terminal-status guard above means that can only ever happen once), so it's the
            // right place to generate the counsellor's earnings for it -- not a separate
            // listener a future change could forget to wire up on one of this action's two
            // callers (the webhook job and the verify-callback fallback).
            if ($status === TransactionStatusEnum::success->value) {
                GenerateCounsellorEarningsAction::new()->execute($transaction);
                CaptureOrganizationPaymentInstrumentAction::new()->execute($transaction, $gatewayData['authorization'] ?? []);
            }

            return $transaction;
        });
    }
}
