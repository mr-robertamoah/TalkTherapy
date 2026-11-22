<?php

namespace App\Services;

use App\Actions\EnsureAddedbyIsValidAction;
use App\Actions\TherapyCase\CreateCaseAction;
use App\Actions\TherapyCase\EnsureCaseNameIsUniqueAction;
use App\Actions\TherapyCase\EnsureUserCanCreateCaseAction;
use App\DTOs\CreateCaseDTO;
use App\Enums\PaginationEnum;
use App\Http\Resources\TherapyCaseResource;
use App\Models\TherapyCase;

class TherapyCaseService extends Service
{
    public function getCases(string $name = '')
    {
        $query = TherapyCase::query();

        if ($name) {
            $query->whereContains('name', (string) $name);
        }

        return TherapyCaseResource::collection($query->paginate(
            PaginationEnum::preferencesPagination->value
        ));
    }

    public function createCase(CreateCaseDTO $createCaseDTO)
    {
        EnsureAddedbyIsValidAction::new()->execute($createCaseDTO);

        EnsureUserCanCreateCaseAction::new()->execute($createCaseDTO->user);

        EnsureCaseNameIsUniqueAction::new()->execute($createCaseDTO->name);

        $therapyCase = CreateCaseAction::new()->execute($createCaseDTO);

        return $therapyCase;
    }
}
