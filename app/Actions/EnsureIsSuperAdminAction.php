<?php

namespace App\Actions;

use App\Exceptions\UserException;
use MrRobertAmoah\DTO\BaseDTO;

class EnsureIsSuperAdminAction extends Action
{
    public function execute(BaseDTO $dto, ?string $errorMessage = null)
    {
        if ($dto->user?->isSuperAdmin()) return;

        throw new UserException($errorMessage ?: 'You must be a super administrator to perform this action.', 422);
    }
}