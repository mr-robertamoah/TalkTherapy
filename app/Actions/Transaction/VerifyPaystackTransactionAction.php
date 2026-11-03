<?php

namespace App\Actions\Transaction;

use App\Actions\Action;
use App\DTOs\TransactionDTO;
use App\Enums\TransactionStatusSourceEnum;
use App\Exceptions\TransactionException;
use App\Models\Transaction;
use Illuminate\Http\Client\HttpClientException;

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
            return ResolveTransactionWithPaystackAction::new()->execute($transaction, TransactionStatusSourceEnum::verify->value);
        } catch (HttpClientException $exception) { // SCRUM-272: an error response or no response at all
            throw new TransactionException('Unable to verify the payment right now. Please try again shortly.', 502, $exception);
        }
    }
}
