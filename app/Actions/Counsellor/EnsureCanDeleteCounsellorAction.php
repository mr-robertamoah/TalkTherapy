<?php

namespace App\Actions\Counsellor;
use App\Actions\Action;
use App\DTOs\DeleteCounsellorDTO;
use App\Exceptions\CannotDeleteCounsellorException;

class EnsureCanDeleteCounsellorAction extends Action
{
    public function execute(DeleteCounsellorDTO $deleteCounsellorDTO)
    {
        if (
            (
                $deleteCounsellorDTO->user?->isAdmin() ||
                $deleteCounsellorDTO->user?->is($deleteCounsellorDTO->counsellor?->user)
            ) && $deleteCounsellorDTO->counsellor->hasNoPendingSessions()
        ) return;

        throw new CannotDeleteCounsellorException("You are either not authorized to delete this counsellor account or there are some sessions to finish.", 422);
    }
}