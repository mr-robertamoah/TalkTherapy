<?php

namespace App\Services;

use App\Actions\EnsureAddedbyIsValidAction;
use App\Actions\Language\CreateLanguageAction;
use App\Actions\Language\EnsureLanguageNameIsUniqueAction;
use App\Actions\Language\EnsureUserCanCreateLanguageAction;
use App\DTOs\CreateLanguageDTO;
use App\Enums\PaginationEnum;
use App\Http\Resources\LanguageResource;
use App\Models\Language;

class LanguageService extends Service
{
    public function getLanguages(string $name = '')
    {
        $query = Language::query();

        if ($name) {
            $query->whereContains('name', (string) $name);
        }

        return LanguageResource::collection($query->paginate(
            PaginationEnum::preferencesPagination->value
        ));
    }

    public function createLanguage(CreateLanguageDTO $createLanguageDTO)
    {
        EnsureAddedbyIsValidAction::new()->execute($createLanguageDTO);

        EnsureUserCanCreateLanguageAction::new()->execute($createLanguageDTO->user);

        EnsureLanguageNameIsUniqueAction::new()->execute($createLanguageDTO->name);

        $language = CreateLanguageAction::new()->execute($createLanguageDTO);

        return $language;
    }
}
