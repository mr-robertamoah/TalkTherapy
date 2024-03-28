<?php

namespace App\Actions\Counsellor;

use App\Actions\Action;
use App\DTOs\VerifyCounsellorDTO;
use App\Exceptions\CounsellorDoesNotHavePendingVerificationRequestException;

class EnsureCounsellorDoesNotHavePendingVerificationRequestAction extends Action
{
    public function execute(VerifyCounsellorDTO $dto)
    {
        if (!$dto->counsellor->hasPendingCounsellorVerificationRequest()) return;

        throw new CounsellorDoesNotHavePendingVerificationRequestException("{$dto->counsellor->getName()} already has a pending verification request.", 422);
    }
}