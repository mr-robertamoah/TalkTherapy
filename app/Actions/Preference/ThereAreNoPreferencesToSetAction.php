<?php

namespace App\Actions\Preference;

use App\Actions\Action;
use App\DTOs\SetPreferenceDTO;

class ThereAreNoPreferencesToSetAction extends Action
{
    public function execute(SetPreferenceDTO $setPreferenceDTO)
    {
        // SCRUM-113: an empty list is a choice too (it clears that preference) -- only nothing at all
        // sent is nothing to set.
        return is_null($setPreferenceDTO->anonymous)
            && is_null($setPreferenceDTO->selectedCases)
            && is_null($setPreferenceDTO->selectedLanguages)
            && is_null($setPreferenceDTO->selectedReligions);
    }
}
