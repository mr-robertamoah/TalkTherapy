<?php

namespace App\Actions\Counsellor;

use App\Actions\Action;
use App\DTOs\UpdateCounsellorDTO;
use App\Exceptions\CounsellorException;
use Illuminate\Support\Facades\URL;

class EnsureUrlSignatureIsValidAction extends Action
{
    public function execute(UpdateCounsellorDTO $dto)
    {
        if (
            !URL::hasValidSignature($dto->request)
        ) throw new CounsellorException('The verification link used is not valid.', 422);

        if (
            !URL::signatureHasNotExpired($dto->request)
        ) throw new CounsellorException('The signature has expired. Please request for another verification and do well to verify email on time.', 422);
    }
}