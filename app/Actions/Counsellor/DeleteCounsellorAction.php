<?php

namespace App\Actions\Counsellor;

use App\Actions\Action;
use App\DTOs\DeleteCounsellorDTO;

class DeleteCounsellorAction extends Action
{
    public function execute(DeleteCounsellorDTO $deleteCounsellorDTO)
    {
        // TODO clean up before deletion
        return $deleteCounsellorDTO->counsellor->delete();
    }
}