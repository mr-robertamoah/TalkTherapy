<?php

namespace App\Actions\Counsellor;
use App\Actions\Action;
use App\DTOs\UpdateCounsellorDTO;
use App\Exceptions\DataProvidedNotValidException;

class EnsureDataValidityAction extends Action
{
    public function execute(UpdateCounsellorDTO $updateCounsellorDTO)
    {
        if (
            $this->isValidArrayOfIds($updateCounsellorDTO->selectedCases) && 
            $this->isValidArrayOfIds($updateCounsellorDTO->selectedLanguages) && 
            $this->isValidArrayOfIds($updateCounsellorDTO->selectedReligions)
        ) return;

        throw new DataProvidedNotValidException("Data for selectedCases, selectedLanguages and selectedRegions may not contain only ids.", 422);
    }

    private function isValidArrayOfIds(array|null $ids)
    {
        if (is_null($ids) || !count($ids)) return true;

        $hasIds = true;

        foreach ($ids as $id) {
            if (is_string($id) || is_int($id)) continue;

            $hasIds = false;
        }

        return $hasIds;
    }
}