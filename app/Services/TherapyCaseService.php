<?php

namespace App\Services;

use App\Actions\EnsureAddedbyIsValidAction;
use App\Actions\Star\CreateStarAction;
use App\Actions\TherapyCase\CreateCaseAction;
use App\Actions\TherapyCase\EnsureCaseNameIsUniqueAction;
use App\Actions\TherapyCase\EnsureUserCanCreateCaseAction;
use App\DTOs\CreateCaseDTO;
use App\DTOs\CreateStarDTO;
use App\Enums\PaginationEnum;
use App\Enums\StarTypeEnum;
use App\Http\Resources\TherapyCaseResource;
use App\Models\TherapyCase;
use App\Models\User;

class TherapyCaseService extends Service
{
    public function getCases(String $name = '') {
        $query = TherapyCase::query();

        if ($name) $query->where('name', 'LIKE', "%{$name}%");

        return TherapyCaseResource::collection($query->paginate(
            PaginationEnum::preferencesPagination->value
        ));
    }

    public function createCase(CreateCaseDTO $createCaseDTO) {
        EnsureAddedbyIsValidAction::new()->execute($createCaseDTO);

        EnsureUserCanCreateCaseAction::new()->execute($createCaseDTO->user);
        
        EnsureCaseNameIsUniqueAction::new()->execute($createCaseDTO->name);

        $therapyCase = CreateCaseAction::new()->execute($createCaseDTO);

        CreateStarAction::new()->execute(
            CreateStarDTO::fromArray([
                'starredby' => null,
                'starred' => $createCaseDTO->addedby ? $createCaseDTO->addedby : $createCaseDTO->user,
                'starreable' => $therapyCase,
                'type' => StarTypeEnum::contribution->value,
            ])
        );
        
        return $therapyCase;
    }
}