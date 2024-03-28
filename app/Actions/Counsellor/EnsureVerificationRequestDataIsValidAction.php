<?php

namespace App\Actions\Counsellor;

use App\Actions\Action;
use App\DTOs\VerifyCounsellorDTO;
use App\Exceptions\InvalidVerificationRequestData;

class EnsureVerificationRequestDataIsValidAction extends Action
{
    public function execute(VerifyCounsellorDTO $verifyCounsellorDTO)
    {
        if (
            ($verifyCounsellorDTO->nationalIdFile || $verifyCounsellorDTO->nationalIdNumber) &&
            ($verifyCounsellorDTO->licenseFile || $verifyCounsellorDTO->licenseNumber)
        ) return;

        throw new InvalidVerificationRequestData("Enough data was not provided to send verification request.", 422);
    }
}