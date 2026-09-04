<?php

namespace App\Actions\Transaction;

use App\Actions\Action;
use App\DTOs\TransactionDTO;
use App\Enums\TransactionStatusEnum;
use App\Enums\TransactionStatusSourceEnum;
use App\Exceptions\TransactionException;
use App\Models\Transaction;
use App\Services\Paystack\PaystackClient;
use Illuminate\Http\Client\RequestException;

class VerifyPaystackTransactionAction extends Action
{
    // The fallback path for when webhook delivery is delayed or missed entirely -- called from
    // the browser callback route Paystack redirects back to after checkout.
    public function execute(TransactionDTO $dto): Transaction
    {
        $transaction = FindTransactionByReferenceAction::new()->execute($dto->reference);

        if (! $transaction) {
            throw new TransactionException('Transaction not found.', 404);
        }

        // Reference alone isn't enough to trust the caller -- without this, any signed-in user
        // could verify (and see the gateway response for) a transaction reference belonging to
        // someone else, just by guessing/observing it.
        if (! $dto->user || $transaction->user_id !== $dto->user->id) {
            throw new TransactionException('Transaction not found.', 404);
        }

        try {
            $response = PaystackClient::new()->verifyTransaction($dto->reference);
        } catch (RequestException $exception) {
            throw new TransactionException('Unable to verify the payment right now. Please try again shortly.', 502);
        }

        // Paystack's real API returns several non-terminal statuses too (e.g. "processing",
        // "queued", "ongoing") -- only 'success'/'failed'/'abandoned' are actually terminal.
        // Collapsing anything else into one of those would prematurely finalize a transaction
        // that a later webhook (or a later verify call) still needs to be able to resolve.
        $status = match ($response['data']['status'] ?? null) {
            'success' => TransactionStatusEnum::success->value,
            'failed' => TransactionStatusEnum::failed->value,
            'abandoned' => TransactionStatusEnum::abandoned->value,
            default => null,
        };

        if (is_null($status)) {
            return $transaction;
        }

        // A mismatch here throws (SCRUM-117), surfacing as an error to the browser callback
        // instead of silently marking a partial/wrong-currency payment as a full success.
        if ($status === TransactionStatusEnum::success->value) {
            EnsureTransactionAmountAndCurrencyMatchAction::new()->execute(
                $transaction,
                isset($response['data']['amount']) ? (int) $response['data']['amount'] : null,
                $response['data']['currency'] ?? null,
                TransactionStatusSourceEnum::verify->value
            );
        }

        return RecordTransactionStatusAction::new()->execute(
            $transaction,
            $status,
            TransactionStatusSourceEnum::verify->value,
            $response['data']['gateway_response'] ?? null,
            $response['data'] ?? null
        );
    }
}
