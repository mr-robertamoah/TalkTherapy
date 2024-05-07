<?php

namespace App\Actions\HowTo;

use App\Actions\Action;
use App\DTOs\CreateHowToDTO;
use App\Exceptions\HowToException;

class EnsurePositionsAreValidAction extends Action
{
    public function execute(CreateHowToDTO $createHowToDTO, string $action = 'create')
    {
        if ($action == 'create') {
            return $this->validateForCreate($createHowToDTO);
        }

        $this->validateForUpdate($createHowToDTO);
    }

    private function validateForCreate(CreateHowToDTO $createHowToDTO)
    {
        $positions = array_map(fn ($howToStep) => $howToStep['position'], $createHowToDTO->howToSteps);
        $positions = array_filter($positions, fn ($p) => $p > 0);
        
        if (
            count(array_unique($positions)) == count($createHowToDTO->howToSteps)
        ) return;

        throw new HowToException("The positions provided for the how-to-steps are not valid. Ensure each one has a unique non-zero position.", 422);
    }

    private function validateForUpdate(CreateHowToDTO $createHowToDTO)
    {
        if (
            !count($createHowToDTO->addedHowToSteps) &&
            !count($createHowToDTO->deletedHowToSteps) &&
            !count($createHowToDTO->howToSteps)
        ) return;

        $updatedPositions = array_map(fn($howToStep) => $howToStep['position'], $createHowToDTO->howToSteps);
        $addedPositions = array_map(fn($howToStep) => $howToStep['position'], $createHowToDTO->addedHowToSteps);
        $newPositions = array_merge($updatedPositions, $addedPositions);
        
        if (count(array_unique(array_filter($newPositions, fn ($p) => $p > 0))) !== count($newPositions))
            throw new HowToException("The positions of the added how-to-steps must be unique from the updated ones. Ensure the positions are not zero.", 422);
        
        $deletedAndUpdatedIds = array_merge(array_map(
            fn ($howToStep) => $howToStep['id'], 
            $createHowToDTO->deletedHowToSteps
        ), array_map(
            fn ($howToStep) => $howToStep['id'], 
            $createHowToDTO->howToSteps
        ));

        $existingPositions = array_map(
            fn ($howToStep) => $howToStep['position'],
            $createHowToDTO->howTo
            ->howToSteps()
            ->whereNotIds($deletedAndUpdatedIds)
            ->get()
            ->toArray()
        );

        ds($newPositions, $existingPositions);
        $mergedPositions = array_merge($newPositions, $existingPositions);

        if (
            count(array_unique($mergedPositions)) == count($mergedPositions)
        ) return;

        throw new HowToException("The positions provided for the how-to-steps are not valid. Ensure each one has a unique non-zero position from the existing how-to-steps.", 422);
    }
}