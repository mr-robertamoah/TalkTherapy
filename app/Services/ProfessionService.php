<?php

namespace App\Services;

use App\Actions\EnsureAddedbyIsValidAction;
use App\Actions\Profession\CreateProfessionAction;
use App\Actions\Profession\EnsureProfessionNameIsUniqueAction;
use App\Actions\Profession\EnsureUserCanCreateProfessionAction;
use App\DTOs\CreateProfessionDTO;
use App\Enums\PaginationEnum;
use App\Http\Resources\ProfessionResource;
use App\Models\Profession;

class ProfessionService extends Service
{
    public function getProfessions(string $name = '')
    {
        $query = Profession::query();

        if ($name) {
            $query->whereContains('name', (string) $name);
        }

        return ProfessionResource::collection($query->paginate(
            PaginationEnum::preferencesPagination->value
        ));
    }

    public function createProfession(CreateProfessionDTO $createProfessionDTO)
    {
        EnsureAddedbyIsValidAction::new()->execute($createProfessionDTO);

        EnsureUserCanCreateProfessionAction::new()->execute($createProfessionDTO);

        EnsureProfessionNameIsUniqueAction::new()->execute($createProfessionDTO->name);

        $profession = CreateProfessionAction::new()->execute($createProfessionDTO);

        return $profession;
    }
}
