<?php

namespace App\Actions;

use App\Exceptions\UserException;
use MrRobertAmoah\DTO\BaseDTO;

class EnsureIsSuperAdminAction extends Action
{
    // $status: 422 by default (the existing callers); TT-7.10b/SCRUM-468 passes 403 for a refusal by role.
    public function execute(BaseDTO $dto, ?string $errorMessage = null, int $status = 422)
    {
        if ($dto->user?->isSuperAdmin()) {
            return;
        }

        throw new UserException($errorMessage ?: 'You must be a super administrator to perform this action.', $status);
    }
}
