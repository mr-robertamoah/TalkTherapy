<?php

namespace App\Actions\Counsellor;

use App\Actions\Action;
use App\DTOs\UpdateCounsellorDTO;

class VerifyEmailAction extends Action
{
    public function execute(UpdateCounsellorDTO $dto)
    {
        return $dto->counsellor->update([
            'email_verified_at' => now()
        ]);
    }
}