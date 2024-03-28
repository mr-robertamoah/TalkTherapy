<?php

namespace App\Actions\Counsellor;
use App\Actions\Action;
use App\DTOs\UpdateCounsellorDTO;
use App\DTOs\VerifyCounsellorDTO;
use App\Exceptions\CannotUpdateCounsellorException;

class EnsureCanUpdateCounsellorAction extends Action
{
    public function execute(UpdateCounsellorDTO|VerifyCounsellorDTO $updateCounsellorDTO)
    {
        if (
            $updateCounsellorDTO->user?->isAdmin() ||
            $updateCounsellorDTO->user?->is($updateCounsellorDTO->counsellor?->user)
        ) return;

        throw new CannotUpdateCounsellorException("You are not authorized to update this counsellor.", 422);
    }
}