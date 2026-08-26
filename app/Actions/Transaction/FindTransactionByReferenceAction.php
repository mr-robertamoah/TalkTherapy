<?php

namespace App\Actions\Transaction;

use App\Actions\Action;
use App\Models\Transaction;

class FindTransactionByReferenceAction extends Action
{
    public function execute(?string $reference): ?Transaction
    {
        if (is_null($reference)) {
            return null;
        }

        return Transaction::query()->whereReference($reference)->first();
    }
}
