<?php

namespace App\Services;

use App\Actions\EnsureAddedbyIsValidAction;
use App\Actions\Profession\CreateProfessionAction;
use App\Actions\Profession\EnsureProfessionNameIsUniqueAction;
use App\Actions\Profession\EnsureUserCanCreateProfessionAction;
use App\Actions\Star\CreateStarAction;
use App\DTOs\CreateProfessionDTO;
use App\DTOs\CreateStarDTO;
use App\Enums\PaginationEnum;
use App\Enums\StarTypeEnum;
use App\Http\Resources\ProfessionResource;
use App\Models\Profession;
use App\Models\User;

class ProfessionService extends Service
{
    
    public function getProfessions(String $name = '') {
        $query = Profession::query();

        if ($name) $query->where('name', 'LIKE', "%{$name}%");

        return ProfessionResource::collection($query->paginate(
            PaginationEnum::preferencesPagination->value
        ));
    }

    public function createProfession(CreateProfessionDTO $createProfessionDTO) {
        EnsureAddedbyIsValidAction::new()->execute($createProfessionDTO);
        
        EnsureUserCanCreateProfessionAction::new()->execute($createProfessionDTO);
        
        EnsureProfessionNameIsUniqueAction::new()->execute($createProfessionDTO->name);

        $profession = CreateProfessionAction::new()->execute($createProfessionDTO);

        CreateStarAction::new()->execute(
            CreateStarDTO::fromArray([
                'starredby' => null,
                'starred' => $createProfessionDTO->addedby
                ? (
                    $createProfessionDTO->addedby::class == User::class
                        ? $createProfessionDTO->addedby
                        : $createProfessionDTO->addedby->user
                ) 
                : $createProfessionDTO->user,
                'starreable' => $profession,
                'type' => StarTypeEnum::contribution->value,
            ])
        );

        return $profession;
    }
}