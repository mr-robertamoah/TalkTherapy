<?php

namespace App\Services;

use App\Actions\EnsureAddedbyIsValidAction;
use App\Actions\Religion\CreateReligionAction;
use App\Actions\Religion\EnsureReligionNameIsUniqueAction;
use App\Actions\Religion\EnsureUserCanCreateReligionAction;
use App\DTOs\CreateReligionDTO;
use App\Enums\PaginationEnum;
use App\Http\Resources\ReligionResource;
use App\Models\Religion;

class ReligionService extends Service
{
    public function getReligions(string $name = '')
    {
        $query = Religion::query();

        if ($name) {
            $query->whereContains('name', (string) $name);
        }

        return ReligionResource::collection($query->paginate(
            PaginationEnum::preferencesPagination->value
        ));
    }

    public function createReligion(CreateReligionDTO $createReligionDTO)
    {
        EnsureAddedbyIsValidAction::new()->execute($createReligionDTO);

        EnsureUserCanCreateReligionAction::new()->execute($createReligionDTO->user);

        EnsureReligionNameIsUniqueAction::new()->execute($createReligionDTO->name);

        $religion = CreateReligionAction::new()->execute($createReligionDTO);

        return $religion;
    }
}
