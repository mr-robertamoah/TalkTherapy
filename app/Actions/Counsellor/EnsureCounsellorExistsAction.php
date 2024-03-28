<?php

namespace App\Actions\Counsellor;

use App\Actions\Action;
use App\DTOs\DeleteCounsellorDTO;
use App\DTOs\UpdateCounsellorDTO;
use App\DTOs\VerifyCounsellorDTO;
use App\Exceptions\CounsellorNotFoundException;

class EnsureCounsellorExistsAction extends Action
{
    public function execute(UpdateCounsellorDTO|DeleteCounsellorDTO|VerifyCounsellorDTO $dto)
    {
        if ($dto->counsellor) return;

        throw new CounsellorNotFoundException('No counsellor was found.', 422);
    }
}