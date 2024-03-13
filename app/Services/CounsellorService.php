<?php

namespace App\Services;

use App\Actions\Counsellor\CreateCounsellorAction;
use App\Actions\Counsellor\EnsureCanCreateCounsellorAction;
use App\Actions\Counsellor\EnsureCanUpdateCounsellorAction;
use App\Actions\Counsellor\EnsureCounsellorExistsAction;
use App\Actions\Counsellor\EnsureDataAdequacyAction;
use App\Actions\Counsellor\EnsureUserCanBecomeCounsellorAction;
use App\Actions\Counsellor\UpdateCounsellorAction;
use App\Actions\EnsureNameStaysRetrievableAction;
use App\DTOs\CheckNameRetrievabilityDTO;
use App\DTOs\CreateCounsellorDTO;
use App\DTOs\UpdateCounsellorDTO;
use App\Models\Counsellor;

class CounsellorService extends Service
{
    public function getCounsellorData(): array
    {
        $data = [
            'loadedCases' => TherapyCaseService::new()->getCases(),
            'loadedLanguages' => LanguageService::new()->getLanguages(),
            'loadedReligions' => ReligionService::new()->getReligions(),
            'loadedProfessions' => ProfessionService::new()->getProfessions(),
        ];

        return $data;
    }

    public function createCounsellor(CreateCounsellorDTO $createCounsellorDTO): Counsellor
    {
        $createCounsellorDTO = EnsureDataAdequacyAction::new()->execute($createCounsellorDTO);

        EnsureCanCreateCounsellorAction::new()->execute($createCounsellorDTO);

        EnsureUserCanBecomeCounsellorAction::new()->execute($createCounsellorDTO);

        return CreateCounsellorAction::new()->execute($createCounsellorDTO);
    }

    public function updateCounsellor(UpdateCounsellorDTO $updateCounsellorDTO): Counsellor
    {
        EnsureCounsellorExistsAction::new()->execute($updateCounsellorDTO);

        EnsureCanUpdateCounsellorAction::new()->execute($updateCounsellorDTO);

        EnsureNameStaysRetrievableAction::new()->execute(
            CheckNameRetrievabilityDTO::new()->fromArray([
                'newName' => $updateCounsellorDTO->name,
                'changing' => 'counsellor',
                'user' => $updateCounsellorDTO->user,
            ])
        );

        return UpdateCounsellorAction::new()->execute($updateCounsellorDTO);
    }
}