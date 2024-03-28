<?php

namespace App\Actions\Counsellor;

use App\Actions\Action;
use App\DTOs\VerifyCounsellorDTO;
use App\Exceptions\VerificationRequestRequirementException;

class CheckVerificationRequestRequirmentAction extends Action
{
    public function execute(VerifyCounsellorDTO $verifyCounsellorDTO)
    {
        if (
            !is_null($verifyCounsellorDTO->user->gender) &&
            !is_null($verifyCounsellorDTO->counsellor->email_verified_at) &&
            ($verifyCounsellorDTO->counsellor->phone && $verifyCounsellorDTO->counsellor->email)
        ) return;

        throw new VerificationRequestRequirementException("Requirement for verification not met.", 422);
    }
}