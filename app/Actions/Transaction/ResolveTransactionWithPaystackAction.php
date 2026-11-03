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

        // Paystack's real API returns several non-terminal statuses too (e.g. "processing",
        // "queued", "ongoing") -- only 'success'/'failed'/'abandoned' are actually terminal.
        // Collapsing anything else into one of those would prematurely finalize a transaction
        // that a later webhook (or a later verify call) still needs to be able to resolve.
        $status = match ($response['data']['status'] ?? null) {
            'success' => TransactionStatusEnum::success->value,
            'failed' => TransactionStatusEnum::failed->value,
            'abandoned' => $serverCharge ? TransactionStatusEnum::failed->value : TransactionStatusEnum::abandoned->value,
            default => null,
        };

        if (is_null($status)) {
            return $transaction;
        }

        // A mismatch here throws (SCRUM-117) instead of silently marking a partial or
        // wrong-currency payment as a full success.
        if ($status === TransactionStatusEnum::success->value) {
            EnsureTransactionAmountAndCurrencyMatchAction::new()->execute(
                $transaction,
                isset($response['data']['amount']) ? (int) $response['data']['amount'] : null,
                $response['data']['currency'] ?? null,
                $source
            );
        }

        return RecordTransactionStatusAction::new()->execute(
            $transaction,
            $status,
            $source,
            $response['data']['gateway_response'] ?? null,
            $response['data'] ?? null
        );
    }

    // Paystack answers a verify for a reference it never saw with a 4xx "Transaction reference not found".
    private function referenceUnknown(RequestException $exception): bool
    {
        return $exception->response->clientError()
            && str_contains(strtolower((string) $exception->response->json('message')), 'not found');
    }
}
