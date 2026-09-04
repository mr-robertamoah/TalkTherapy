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

        $status = match ($response['data']['status'] ?? null) {
            'success' => TransactionStatusEnum::success->value,
            'failed' => TransactionStatusEnum::failed->value,
            'abandoned' => TransactionStatusEnum::abandoned->value,
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
