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
            $deleteCounsellorDTO->user?->isAdmin() ||
            $deleteCounsellorDTO->user?->is($deleteCounsellorDTO->counsellor?->user)
        ) return;

        throw new CannotDeleteCounsellorException("You are not authorized to delete this counsellor.", 422);
    }
}