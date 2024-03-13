<?php

namespace App\Actions\Profession;

use App\Actions\Action;
use App\DTOs\CreateProfessionDTO;
use App\Exceptions\UserCanCreateProfessionException;
use App\Models\Counsellor;

class EnsureUserCanCreateProfessionAction extends Action
{
    public function execute(CreateProfessionDTO $createProfessionDTO) {
        if (
            (
                $createProfessionDTO->addedby &&
                $createProfessionDTO->addedby::class == Counsellor::class &&
                $createProfessionDTO->addedby->user->is($createProfessionDTO->user)    
            ) ||
            $createProfessionDTO->user?->isAdmin()
        ) return;

        throw new UserCanCreateProfessionException('You are not allowed to create a profession.', 422);
    }
}