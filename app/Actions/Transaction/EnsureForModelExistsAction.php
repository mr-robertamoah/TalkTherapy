<?php

namespace App\Actions\Transaction;

use App\Actions\Action;
use App\DTOs\TransactionDTO;
use App\Exceptions\TransactionException;

class EnsureForModelExistsAction extends Action
{
    public function execute(TransactionDTO $dto)
    {
        if ($dto->for) {
            return;
        }

        throw new TransactionException('The item you are trying to pay for was not found.', 422);
    }
}
