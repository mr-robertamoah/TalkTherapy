<?php

namespace App\Actions;

use App\Exceptions\AddedbyIsInvalidException;
use App\Models\Counsellor;
use MrRobertAmoah\DTO\BaseDTO;

class EnsureIsAdminAction extends Action
{
    public function execute(BaseDTO $dto, ?string $errorMessage = null)
    {
        if ($dto->user?->isAdmin()) return;

        throw new AddedbyIsInvalidException($errorMessage ?: 'You must be an administrator to perform this action.', 422);
    }
}