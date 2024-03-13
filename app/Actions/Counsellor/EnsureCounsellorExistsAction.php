<?php

namespace App\Actions\Counsellor;

use App\Actions\Action;
use App\DTOs\UpdateCounsellorDTO;
use App\Exceptions\CounsellorNotFoundException;

class EnsureCounsellorExistsAction extends Action
{
    public function execute(UpdateCounsellorDTO $updateCounsellorDTO)
    {
        if ($updateCounsellorDTO->counsellor) return;

        throw new CounsellorNotFoundException('No counsellor was found.', 422);
    }
}