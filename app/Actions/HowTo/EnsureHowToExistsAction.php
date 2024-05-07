<?php

namespace App\Actions\HowTo;

use App\Actions\Action;
use App\DTOs\CreateHowToDTO;
use App\Exceptions\HowToException;

class EnsureHowToExistsAction extends Action
{
    public function execute(CreateHowToDTO $createHowToDTO)
    {
        if (
            $createHowToDTO->howTo
        ) return;

        throw new HowToException("The how-to was not found.", 422);
    }
}