<?php

namespace App\Services;

use App\Actions\Transaction\ChargeOrganizationForModelAction;
use App\Actions\Transaction\EnsureCanInitiateChargeAction;
use App\Actions\Transaction\EnsureCanPayForModelAction;
use App\Actions\Transaction\EnsureForModelExistsAction;
use App\Actions\Transaction\EnsureOrganizationCanPayForModelAction;
use App\Actions\Transaction\EnsureWebhookSignatureIsValidAction;
use App\Actions\Transaction\InitiatePaystackChargeAction;
use App\Actions\Transaction\RequestRefundAction;
use App\Actions\Transaction\VerifyPaystackTransactionAction;
use App\DTOs\TransactionDTO;
use App\Jobs\ProcessPaystackWebhookJob;
use App\Models\Request;
use App\Models\Transaction;
use App\Models\User;

class TransactionService extends Service
{
    /**
     * @return array{transaction: Transaction, authorizationUrl: ?string}
     */
    public function initiateCharge(TransactionDTO $transactionDTO): array
    {
        EnsureForModelExistsAction::new()->execute($transactionDTO);

        EnsureCanPayForModelAction::new()->execute($transactionDTO);

        EnsureCanInitiateChargeAction::new()->execute($transactionDTO);

        EnsureOrganizationCanPayForModelAction::new()->execute($transactionDTO);

        // TT-7.3b-c/SCRUM-234: by this point, a non-null $transactionDTO->organization has
        // already been fully validated as pay-per-use-eligible by the ensure-chain above --
        // route it through the real org-charge primitive (charges the ORG's saved instrument at
        // actual cost) instead of the member's own card at the client's listed price. SCRUM-420:
        // a group too, once per member -- the member's own card is never charged with the
        // organisation as a label any more; if the organisation can't pay, it's refused.
        if ($transactionDTO->organization) {
            return [
                'transaction' => ChargeOrganizationForModelAction::new()->execute($transactionDTO),
                'authorizationUrl' => null,
            ];
        }

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

    // TT-7.7b/SCRUM-250
    public function requestRefund(?User $user, ?Transaction $transaction, ?string $reason): Request
    {
        return RequestRefundAction::new()->execute($user, $transaction, $reason);
    }
}
