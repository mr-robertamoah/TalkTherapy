<?php

namespace App\Services;

use App\Actions\Transaction\ChargeOrganizationForModelAction;
use App\Actions\Transaction\EnsureCanInitiateChargeAction;
use App\Actions\Transaction\EnsureCanPayForModelAction;
use App\Actions\Transaction\EnsureForModelExistsAction;
use App\Actions\Transaction\EnsureOrganizationCanPayForModelAction;
use App\Actions\Transaction\EnsureWebhookSignatureIsValidAction;
use App\Actions\Transaction\InitiatePaystackChargeAction;
use App\Actions\Transaction\ResolveTransactionSubjectAction;
use App\Actions\Transaction\VerifyPaystackTransactionAction;
use App\DTOs\TransactionDTO;
use App\Jobs\ProcessPaystackWebhookJob;
use App\Models\GroupTherapy;
use App\Models\Transaction;

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
        // actual cost) instead of the member's own card at the client's listed price.
        //
        // GroupTherapy is the one deliberate exception, unchanged from TT-7.3a's original
        // behavior: ChargeOrganizationForModelAction doesn't support it yet (TT-7.3b-b's own
        // scope boundary -- multiple counsellors, no defined "actual cost" model), so an org-paid
        // GroupTherapy still falls through to the existing member's-card path below, exactly as
        // it does today. Revisit once GroupTherapy org billing is actually designed.
        $resolvedFor = ResolveTransactionSubjectAction::new()->execute($transactionDTO->for);

        if ($transactionDTO->organization && ! $resolvedFor instanceof GroupTherapy) {
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
}
