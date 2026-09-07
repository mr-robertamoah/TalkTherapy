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

            RecordTransactionStatusAction::new()->execute(
                $transaction,
                TransactionStatusEnum::failed->value,
                TransactionStatusSourceEnum::orgSettlement->value,
                'Paystack could not process this settlement charge.'
            );

            return;
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
}
