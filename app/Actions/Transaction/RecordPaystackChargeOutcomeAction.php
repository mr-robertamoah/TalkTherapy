<?php

namespace App\Actions\Transaction;

use App\Actions\Action;
use App\Enums\TransactionStatusEnum;
use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

// SCRUM-378: maps a Paystack charge/verify RESPONSE (`data.status`/amount/currency/gateway_response --
// chargeAuthorization() and verifyTransaction() share that shape) to our transaction status, shared by
// ResolveTransactionWithPaystackAction, ChargeOrganizationForModelAction and
// ProcessOrganizationInvoiceSettlementJob. SCRUM-379: ProcessPaystackWebhookJob shares the
// "check amount on success, then record" half via recordOutcome() -- its status comes from the event
// name (charge.success/charge.failed), not data.status, so it maps that itself. Only
// 'success'/'failed'/'abandoned' are terminal on
// Paystack's side; anything else ("processing", "queued", "ongoing", ...) leaves the transaction as it
// is, so a later webhook or verify can still resolve it. Returns the transaction unchanged then.
//
// $abandonedIsFailure: a server-to-server charge has no person present to finish an interactive step
// (SCRUM-244), so 'abandoned' is recorded as failed, and logged distinctly so it stays
// distinguishable from a genuine decline; a checkout charge records it as abandoned, which
// the customer can still recover from with a fresh checkout. Callers that must not treat 'abandoned' as
// final at all (the settlement retry lookup) filter it out before calling.
class RecordPaystackChargeOutcomeAction extends Action
{
    public function execute(Transaction $transaction, array $data, string $source, bool $abandonedIsFailure): Transaction
    {
        $status = match ($data['status'] ?? null) {
            'success' => TransactionStatusEnum::success->value,
            'failed' => TransactionStatusEnum::failed->value,
            'abandoned' => $abandonedIsFailure ? TransactionStatusEnum::failed->value : TransactionStatusEnum::abandoned->value,
            default => null,
        };

        if (is_null($status)) {
            return $transaction;
        }

        if ($abandonedIsFailure && ($data['status'] ?? null) === 'abandoned') {
            Log::warning('A server-to-server charge was abandoned by Paystack -- treated as a failure since no human is present to complete an interactive step.', [
                'transaction_id' => $transaction->id,
                'reference' => $transaction->reference,
                'source' => $source,
            ]);
        }

        return $this->recordOutcome($transaction, $status, $data, $source);
    }

    // $status is already one of ours (TransactionStatusEnum). A mismatch here throws (SCRUM-117)
    // instead of silently marking a partial or wrong-currency payment as a full success -- for a
    // queued caller (the webhook) that fails the job, which is what alerts admins.
    public function recordOutcome(Transaction $transaction, string $status, array $data, string $source): Transaction
    {
        if ($status === TransactionStatusEnum::success->value) {
            EnsureTransactionAmountAndCurrencyMatchAction::new()->execute(
                $transaction,
                isset($data['amount']) ? (int) $data['amount'] : null,
                $data['currency'] ?? null,
                $source
            );
        }

        return RecordTransactionStatusAction::new()->execute(
            $transaction,
            $status,
            $source,
            $data['gateway_response'] ?? null,
            $data
        );
    }
}
