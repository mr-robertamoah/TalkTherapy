<?php

namespace App\Actions\Transaction;

use App\Actions\Action;
use App\Exceptions\TransactionException;
use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

class EnsureTransactionAmountAndCurrencyMatchAction extends Action
{
    // Signature verification only rules out a forged event -- it says nothing about whether a
    // legitimately-signed 'success' event's own amount/currency actually match what was charged
    // (a partial capture, a currency mismatch, or some other Paystack-side edge case). Only ever
    // called ahead of recording a 'success' status (SCRUM-117): there's nothing money-correctness
    // sensitive to protect once a charge resolves to anything other than a full success at the
    // originally initiated amount/currency.
    public function execute(Transaction $transaction, ?int $reportedAmount, ?string $reportedCurrency, string $source): void
    {
        if (
            $reportedAmount === $transaction->amount &&
            $reportedCurrency !== null &&
            strtoupper($reportedCurrency) === strtoupper($transaction->currency)
        ) {
            return;
        }

        Log::error('Paystack reported a successful charge whose amount/currency does not match the transaction on record.', [
            'transaction_id' => $transaction->id,
            'reference' => $transaction->reference,
            'expected_amount' => $transaction->amount,
            'expected_currency' => $transaction->currency,
            'reported_amount' => $reportedAmount,
            'reported_currency' => $reportedCurrency,
            'source' => $source,
        ]);

        throw new TransactionException('The reported payment amount or currency does not match this transaction. This has been flagged for review.', 422);
    }
}
