<?php

namespace App\Actions;

use App\Exceptions\AddedbyIsInvalidException;
use App\Models\Counsellor;
use MrRobertAmoah\DTO\BaseDTO;

class EnsureAddedbyIsValidAction extends Action
{
    public function execute(BaseDTO $dto)
    {
        if (is_null($dto->addedby)) return;

        if ($dto->user->isAdmin()) return;

        if ($dto->user->is($dto->addedby)) return;

        if ($dto->addedby::class == Counsellor::class && $dto->user->is($dto->addedby->user)) return;

        throw new AddedbyIsInvalidException('Data on added by is not valid to perform this action.');
    }
}