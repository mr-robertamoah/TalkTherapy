<?php

namespace App\Actions;

use App\Exceptions\AddedbyIsInvalidException;
use App\Models\Counsellor;
use MrRobertAmoah\DTO\BaseDTO;

class EnsureAddedbyIsValidAction extends Action
{
    public function execute(BaseDTO $dto, ?string $errorMessage = null, bool $force = false)
    {
        if (is_null($dto->addedby) && !$force) return;

        if (is_null($dto->addedby) && $force)
            throw new AddedbyIsInvalidException($errorMessage ?: 'Data on added by is required to perform this action.', 422);

        if ($dto->user->isAdmin()) return;

        if ($dto->user->is($dto->addedby)) return;

        if ($dto->addedby::class == Counsellor::class && $dto->user->is($dto->addedby->user)) return;

        throw new AddedbyIsInvalidException($errorMessage ?: 'Data on added by is not valid to perform this action.', 422);
    }
}