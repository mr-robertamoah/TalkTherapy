<?php

namespace App\Jobs;

use App\Actions\Transaction\EnsureTransactionAmountAndCurrencyMatchAction;
use App\Actions\Transaction\RecordTransactionStatusAction;
use App\Enums\TransactionStatusEnum;
use App\Enums\TransactionStatusSourceEnum;
use App\Models\Transaction;
use App\Services\Paystack\PaystackClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// TT-7.3b-e/SCRUM-236: the real Paystack chargeAuthorization() call for a claimed retainer
// invoice settlement, dispatched only after SettleOrganizationInvoiceAction's own DB transaction
// commits (see its comment) -- mirrors ProcessCounsellorPayoutJob's identical split for payouts.
class ProcessOrganizationInvoiceSettlementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const TERMINAL_STATUSES = [
        TransactionStatusEnum::success->value,
        TransactionStatusEnum::failed->value,
    ];

    public function __construct(private int $transactionId)
    {
        //
    }

    public function handle(): void
    {
        $transaction = Transaction::find($this->transactionId);

        if (! $transaction || in_array($transaction->status, self::TERMINAL_STATUSES, true)) {
            return;
        }

        $organization = $transaction->organization;
        $instrument = $organization?->paymentInstrument;

        if (! $instrument) {
            RecordTransactionStatusAction::new()->execute(
                $transaction,
                TransactionStatusEnum::failed->value,
                TransactionStatusSourceEnum::orgSettlement->value,
                'This organization has no payment instrument on file.'
            );

            return;
        }

        try {
            $response = PaystackClient::new()->chargeAuthorization([
                'authorization_code' => $instrument->authorization_code,
                'email' => $transaction->user->email,
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
                'reference' => $transaction->reference,
            ]);
        } catch (RequestException $exception) {
            // Same reasoning as ProcessCounsellorPayoutJob's identical guard: a 5xx means we
            // genuinely don't know whether the charge went through -- rethrow so the job's own
            // retry re-attempts the SAME transaction/reference, never a fresh one.
            if ($exception->response->serverError()) {
                throw $exception;
            }

            // SCRUM-272 (security review): on a RETRY, a 4xx isn't a definite rejection either -- the
            // earlier attempt may have timed out AFTER Paystack processed it, and the retry is then
            // refused as a duplicate. Recording "failed" would invite a second, new transfer/charge.
            // SCRUM-376: so ask Paystack what actually became of this reference instead, and record
            // that below exactly as if the charge itself had answered -- or rethrow (record left as
            // it is for follow-up and the failed-job alert) when the answer isn't final.
            if ($this->attempts() > 1) {
                $response = $this->finalOutcomeOrRethrow($transaction, $exception);
            } else {
                RecordTransactionStatusAction::new()->execute(
                    $transaction,
                    TransactionStatusEnum::failed->value,
                    TransactionStatusSourceEnum::orgSettlement->value,
                    'Paystack could not process this settlement charge.'
                );

                return;
            }
        }

        $paystackStatus = $response['data']['status'] ?? null;

        // SCRUM-244: 'abandoned' left this invoice's own settlement Transaction non-terminal
        // forever -- UpdateOrganizationInvoiceStatusAction's own match() only maps success/failed,
        // so the invoice stayed `pending` permanently (the periodic sweep only re-claims `open`
        // ones), the org was never billed, and its counsellors never got paid for that period,
        // silently. There is no human present on this server-to-server charge to ever complete
        // whatever interactive step Paystack was waiting on, so this is treated as a real failure
        // -- reusing UpdateOrganizationInvoiceStatusAction's EXISTING failed-handling path (invoice
        // -> failed, org -> suspended) rather than adding a third branch there. Logged distinctly
        // so it stays distinguishable from a genuine Paystack decline.
        if ($paystackStatus === 'abandoned') {
            Log::warning('A retainer invoice settlement charge was abandoned by Paystack -- treated as a failure since no human is present to complete an interactive step on a server-to-server charge.', [
                'transaction_id' => $transaction->id,
                'reference' => $transaction->reference,
            ]);
        }

        $status = match ($paystackStatus) {
            'success' => TransactionStatusEnum::success->value,
            'failed', 'abandoned' => TransactionStatusEnum::failed->value,
            default => null,
        };

        if (is_null($status)) {
            return;
        }

        if ($status === TransactionStatusEnum::success->value) {
            EnsureTransactionAmountAndCurrencyMatchAction::new()->execute(
                $transaction,
                isset($response['data']['amount']) ? (int) $response['data']['amount'] : null,
                $response['data']['currency'] ?? null,
                TransactionStatusSourceEnum::orgSettlement->value
            );
        }

        RecordTransactionStatusAction::new()->execute(
            $transaction->fresh(),
            $status,
            TransactionStatusSourceEnum::orgSettlement->value,
            $response['data']['gateway_response'] ?? null,
            $response['data'] ?? null
        );
    }

    // SCRUM-376: only a definite answer comes back -- verifyTransaction()'s response has the same
    // data.status/amount/currency shape as chargeAuthorization()'s, so handle() maps it with the
    // same code (abandoned => failed, amount/currency check on success). Anything else (no record
    // of the reference -- which could just mean the earlier attempt isn't registered yet -- a
    // non-terminal status, Paystack unreachable) is ambiguous, so the original 4xx is rethrown.
    // Deliberately not ResolveTransactionWithPaystackAction: its server-charge mode records
    // "reference not found" as failed, which is exactly the ambiguous case here.
    private function finalOutcomeOrRethrow(Transaction $transaction, RequestException $exception): array
    {
        try {
            $response = PaystackClient::new()->verifyTransaction($transaction->reference);
        } catch (HttpClientException) {
            throw $exception;
        }

        // Security review: not `abandoned` -- Paystack reports a transaction that hasn't been attempted
        // yet as abandoned, which is exactly the state a timed-out first charge can be caught in.
        // Recording that as failed would fail the invoice and suspend the org with no way back if the
        // charge then succeeds. Left pending: Paystack's webhook resolves it, or the failed-job alert
        // flags it for follow-up.
        if (! in_array($response['data']['status'] ?? null, ['success', 'failed'], true)) {
            throw $exception;
        }

        // Same rule as the payout lookup: only this transaction's own charge is recorded (a success's
        // amount and currency are then checked by EnsureTransactionAmountAndCurrencyMatchAction).
        if (($response['data']['reference'] ?? null) !== $transaction->reference) {
            Log::warning('A settlement lookup returned a charge for another reference; left for manual follow-up.', [
                'transaction_id' => $transaction->id,
            ]);

            throw $exception;
        }

        Log::info('A retried settlement charge was refused by Paystack; recorded the outcome Paystack reports for its reference instead.', [
            'transaction_id' => $transaction->id,
            'status' => $response['data']['status'],
        ]);

        return $response;
    }
}
