<?php

namespace App\Services;

use App\Actions\EnsureAddedbyIsValidAction;
use App\Actions\Religion\CreateReligionAction;
use App\Actions\Religion\EnsureReligionNameIsUniqueAction;
use App\Actions\Religion\EnsureUserCanCreateReligionAction;
use App\Actions\Star\CreateStarAction;
use App\DTOs\CreateReligionDTO;
use App\DTOs\CreateStarDTO;
use App\Enums\PaginationEnum;
use App\Enums\StarTypeEnum;
use App\Http\Resources\ReligionResource;
use App\Models\Religion;

class ReligionService extends Service
{
    public function getReligions(String $name = '') {
        $query = Religion::query();

        if ($name) $query->where('name', 'LIKE', "%{$name}%");

        return ReligionResource::collection($query->paginate(
            PaginationEnum::preferencesPagination->value
        ));
    }

    public function createReligion(CreateReligionDTO $createReligionDTO) {
        EnsureAddedbyIsValidAction::new()->execute($createReligionDTO);
        
        EnsureUserCanCreateReligionAction::new()->execute($createReligionDTO->user);
        
        EnsureReligionNameIsUniqueAction::new()->execute($createReligionDTO->name);

        $religion = CreateReligionAction::new()->execute($createReligionDTO);

        CreateStarAction::new()->execute(
            CreateStarDTO::fromArray([
                'starredby' => null,
                'starred' => $createReligionDTO->addedby ? $createReligionDTO->addedby : $createReligionDTO->user,
                'starreable' => $religion,
                'type' => StarTypeEnum::contribution->value,
            ])
        );

        return $religion;
    }
}