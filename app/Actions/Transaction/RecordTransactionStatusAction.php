<?php

namespace App\Actions\Transaction;

use App\Actions\Action;
use App\Enums\TransactionStatusEnum;
use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

class RecordTransactionStatusAction extends Action
{
    private const TERMINAL_STATUSES = [
        TransactionStatusEnum::success->value,
        TransactionStatusEnum::failed->value,
    ];

    // The single choke point both the webhook and the verify-callback fallback call into, so
    // idempotency lives once: Paystack retries webhook delivery, and the verify-callback can
    // race the webhook for the same reference.
    public function execute(Transaction $transaction, string $status, string $source, ?string $message = null): Transaction
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

        $transaction->update(['status' => $status]);

        $transaction->statusHistories()->create([
            'status' => $status,
            'source' => $source,
            'message' => $message,
        ]);

        return $transaction->refresh();
    }
}
