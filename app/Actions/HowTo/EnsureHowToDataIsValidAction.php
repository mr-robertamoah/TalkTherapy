<?php

namespace App\Actions\HowTo;

use App\Actions\Action;
use App\DTOs\CreateHowToDTO;
use App\Exceptions\HowToException;

class EnsureHowToDataIsValidAction extends Action
{
    public function execute(CreateHowToDTO $createHowToDTO, string $action = 'create')
    {
        if (
            $this->canCreate($createHowToDTO) ||
            ($action == 'update' && $this->canUpdate($createHowToDTO))
        ) return;

        throw new HowToException("Enough data was not provided to create the how-to", 422);
    }

    private function canCreate(CreateHowToDTO $createHowToDTO)
    {
        return $createHowToDTO->name &&
            $createHowToDTO->page &&
            $createHowToDTO->howToSteps && 
            count($createHowToDTO->howToSteps);
    }

    private function canUpdate(CreateHowToDTO $createHowToDTO)
    {
        return $createHowToDTO->name ||
            $createHowToDTO->page ||
            ($createHowToDTO->howToSteps && count($createHowToDTO->howToSteps)) ||
            ($createHowToDTO->addedHowToSteps && count($createHowToDTO->addedHowToSteps)) ||
            ($createHowToDTO->deletedHowToSteps && count($createHowToDTO->deletedHowToSteps));
    }
}