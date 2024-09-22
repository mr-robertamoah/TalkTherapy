<?php

namespace App\Actions\HowTo;

use App\Actions\Action;
use App\DTOs\CreateHowToDTO;
use App\Exceptions\HowToException;

class EnsureElementIdsAreValidAction extends Action
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
        $positions = array_map(fn ($howToStep) => $howToStep['elementId'], $createHowToDTO->howToSteps);
        $positions = array_filter($positions, fn ($p) => $p > 0);
        
        if (
            count(array_unique($positions)) == count($createHowToDTO->howToSteps)
        ) return;

        throw new HowToException("The element ids provided for the how-to-steps are not valid. Ensure each one has a unique element Id.", 422);
    }

    private function validateForUpdate(CreateHowToDTO $createHowToDTO)
    {
        if (
            !count($createHowToDTO->addedHowToSteps) &&
            !count($createHowToDTO->deletedHowToSteps) &&
            !count($createHowToDTO->howToSteps)
        ) return;

        $updatedElementIds = array_map(fn($howToStep) => $howToStep['elementId'], $createHowToDTO->howToSteps);
        $addedElementIds = array_map(fn($howToStep) => $howToStep['elementId'], $createHowToDTO->addedHowToSteps);
        $newElementIds = array_merge($updatedElementIds, $addedElementIds);
        
        if (count(array_unique(array_filter($newElementIds, fn ($p) => $p > 0))) !== count($newElementIds))
            throw new HowToException("The element ids of the added how-to-steps must be unique from the updated ones. Ensure the element ids are not zero.", 422);
        
        $deletedAndUpdatedIds = array_merge(array_map(
            fn ($howToStep) => $howToStep['id'], 
            $createHowToDTO->deletedHowToSteps
        ), array_map(
            fn ($howToStep) => $howToStep['id'], 
            $createHowToDTO->howToSteps
        ));

        $existingElementIds = array_map(
            fn ($howToStep) => $howToStep['position'],
            $createHowToDTO->howTo
            ->howToSteps()
            ->whereNotIds($deletedAndUpdatedIds)
            ->get()
            ->toArray()
        );

        ds($newElementIds, $existingElementIds);
        $mergedPositions = array_merge($newElementIds, $existingElementIds);

        if (
            count(array_unique($mergedPositions)) == count($mergedPositions)
        ) return;

        throw new HowToException("The element ids provided for the how-to-steps are not valid. Ensure each one has a unique element ids from the existing how-to-steps.", 422);
    }
}