<?php

namespace App\Actions\Preference;

use App\Actions\Action;
use App\DTOs\SetPreferenceDTO;

class ThereAreNoPreferencesToSetAction extends Action
{
    public function execute(SetPreferenceDTO $setPreferenceDTO)
    {
        if (
            !is_null($setPreferenceDTO->anonymous) ||
            (!is_null($setPreferenceDTO->selectedCases) && count($setPreferenceDTO->selectedCases)) ||
            (!is_null($setPreferenceDTO->selectedLanguages) && count($setPreferenceDTO->selectedLanguages)) ||
            (!is_null($setPreferenceDTO->selectedReligions) && count($setPreferenceDTO->selectedReligions))
        ) return false;

        return true;
    }
}