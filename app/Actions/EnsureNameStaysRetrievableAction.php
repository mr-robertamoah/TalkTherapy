<?php

namespace App\Actions;

use App\DTOs\CheckNameRetrievabilityDTO;
use App\Exceptions\BadRequestException;

class EnsureNameStaysRetrievableAction extends Action
{
    public function execute(CheckNameRetrievabilityDTO $checkNameRetrievabilityDTO)
    {
        if (
            $checkNameRetrievabilityDTO->newName ||
            is_null($checkNameRetrievabilityDTO->user?->counsellor)
        ) return;

        if (
            $checkNameRetrievabilityDTO->changing == 'user' &&
            !is_null($checkNameRetrievabilityDTO->user?->counsellor?->name)
        ) return;

        if (
            $checkNameRetrievabilityDTO->changing == 'counsellor' &&
            !is_null($checkNameRetrievabilityDTO->user?->name)
        ) return;

        throw new BadRequestException('You cannot remove name because your counsellor account has to have a name.', 422);
    }
}