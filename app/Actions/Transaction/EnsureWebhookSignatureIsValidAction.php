<?php

namespace App\Actions\Transaction;

use App\Actions\Action;
use App\DTOs\TransactionDTO;
use App\Exceptions\TransactionException;

class EnsureWebhookSignatureIsValidAction extends Action
{
    public function execute(TransactionDTO $dto)
    {
        // Paystack signs the exact raw request body bytes -- re-serializing the already-decoded
        // payload (e.g. via json_encode) can produce different bytes (key order, whitespace,
        // unicode escaping) and silently break verification, so this must hash $dto->rawBody,
        // never $dto->payload.
        $expected = hash_hmac('sha512', (string) $dto->rawBody, (string) config('services.paystack.secret_key'));

        if (! $dto->signature || ! hash_equals($expected, $dto->signature)) {
            throw new TransactionException('Invalid webhook signature.', 401);
        }
    }
}
