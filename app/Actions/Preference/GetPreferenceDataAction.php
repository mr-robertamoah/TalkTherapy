<?php

namespace App\Actions\Preference;
use App\Actions\Action;
use App\DTOs\SetPreferenceDTO;

class GetPreferenceDataAction extends Action
{
    public function execute(SetPreferenceDTO $setPreferenceDTO)
    {
        $data = [];

        if (!is_null($setPreferenceDTO->anonymous)) {
            $data['anonymous'] = $setPreferenceDTO->anonymous;
        }

        if (!is_null($setPreferenceDTO->selectedCases)) {
            $data['cases'] = $setPreferenceDTO->selectedCases;
        }

        if (!is_null($setPreferenceDTO->selectedLanguages)) {
            $data['languages'] = $setPreferenceDTO->selectedLanguages;
        }

        if (!is_null($setPreferenceDTO->selectedReligions)) {
            $data['religions'] = $setPreferenceDTO->selectedReligions;
        }

        return $data;
    }
}