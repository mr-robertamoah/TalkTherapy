<?php

namespace App\Actions\Transaction;

use App\Actions\Action;
use App\Enums\TransactionStatusEnum;
use App\Models\Transaction;
use App\Services\Paystack\PaystackClient;
use Illuminate\Http\Client\RequestException;

// Asks Paystack what became of a transaction and records the answer through the one status
// choke point (RecordTransactionStatusAction). Shared by the browser callback
// (VerifyPaystackTransactionAction) and the stale-charge reconciliation sweep (SCRUM-248).
// Throws HttpClientException when Paystack can't be reached -- each caller decides what that means.
//
// $serverCharge (an org charge, SCRUM-248 security review): no person is present to finish anything,
// so 'abandoned' is a failure (as ChargeOrganizationForModelAction already treats it), and Paystack
// having no record of the reference means the charge never reached it -- no money moved -- so it is a
// failure too. Only ask in this mode once the charge is old enough that "not found" can't just mean
// "not registered yet" (the reconcile grace period).
class ResolveTransactionWithPaystackAction extends Action
{
    public function execute(Transaction $transaction, string $source, bool $serverCharge = false): Transaction
    {
        try {
            $response = PaystackClient::new()->verifyTransaction($transaction->reference);
        } catch (RequestException $exception) {
            if (! $serverCharge || ! $this->referenceUnknown($exception)) {
                throw $exception;
            }

            return RecordTransactionStatusAction::new()->execute(
                $transaction,
                TransactionStatusEnum::failed->value,
                $source,
                'Paystack has no record of this charge; it never reached Paystack.'
            );
        }

        // SCRUM-378: the status mapping, amount/currency check and recording are shared with the
        // org-charge and settlement paths. 'abandoned' only means failed for a server charge.
        return RecordPaystackChargeOutcomeAction::new()->execute(
            $transaction,
            $response['data'] ?? [],
            $source,
            abandonedIsFailure: $serverCharge
        );
    }

    // Paystack answers a verify for a reference it never saw with a 4xx "Transaction reference not found".
    private function referenceUnknown(RequestException $exception): bool
    {
        return $exception->response->clientError()
            && str_contains(strtolower((string) $exception->response->json('message')), 'not found');
    }
}
