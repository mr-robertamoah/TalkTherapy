<?php

namespace App\Services;

use App\Actions\Transaction\EnsureCanInitiateChargeAction;
use App\Actions\Transaction\EnsureCanPayForModelAction;
use App\Actions\Transaction\EnsureForModelExistsAction;
use App\Actions\Transaction\EnsureOrganizationCanPayForModelAction;
use App\Actions\Transaction\EnsureWebhookSignatureIsValidAction;
use App\Actions\Transaction\InitiatePaystackChargeAction;
use App\Actions\Transaction\VerifyPaystackTransactionAction;
use App\DTOs\TransactionDTO;
use App\Jobs\ProcessPaystackWebhookJob;
use App\Models\Transaction;

class TransactionService extends Service
{
    public function initiateCharge(TransactionDTO $transactionDTO): array
    {
        EnsureForModelExistsAction::new()->execute($transactionDTO);

        EnsureCanPayForModelAction::new()->execute($transactionDTO);

        EnsureCanInitiateChargeAction::new()->execute($transactionDTO);

        EnsureOrganizationCanPayForModelAction::new()->execute($transactionDTO);

        return InitiatePaystackChargeAction::new()->execute($transactionDTO);
    }

    public function handleWebhook(TransactionDTO $transactionDTO): void
    {
        EnsureWebhookSignatureIsValidAction::new()->execute($transactionDTO);

        ProcessPaystackWebhookJob::dispatch($transactionDTO->payload);
    }

    public function verifyTransaction(TransactionDTO $transactionDTO): Transaction
    {
        return VerifyPaystackTransactionAction::new()->execute($transactionDTO);
    }
}
