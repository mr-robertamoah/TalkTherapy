<?php

namespace App\Services;

use App\Actions\EnsureAddedbyIsValidAction;
use App\Actions\Language\CreateLanguageAction;
use App\Actions\Language\EnsureLanguageNameIsUniqueAction;
use App\Actions\Language\EnsureUserCanCreateLanguageAction;
use App\Actions\Star\CreateStarAction;
use App\DTOs\CreateLanguageDTO;
use App\DTOs\CreateStarDTO;
use App\Enums\PaginationEnum;
use App\Enums\StarTypeEnum;
use App\Http\Resources\LanguageResource;
use App\Models\Language;
use App\Models\User;

class LanguageService extends Service
{
    public function getLanguages(String $name = '') {
        $query = Language::query();

        if ($name) $query->where('name', 'LIKE', "%{$name}%");

        return LanguageResource::collection($query->paginate(
            PaginationEnum::preferencesPagination->value
        ));
    }

    public function createLanguage(CreateLanguageDTO $createLanguageDTO) {
        EnsureAddedbyIsValidAction::new()->execute($createLanguageDTO);
        
        EnsureUserCanCreateLanguageAction::new()->execute($createLanguageDTO->user);
        
        EnsureLanguageNameIsUniqueAction::new()->execute($createLanguageDTO->name);

        $language = CreateLanguageAction::new()->execute($createLanguageDTO);

        CreateStarAction::new()->execute(
            CreateStarDTO::fromArray([
                'starredby' => null,
                'starred' => $createLanguageDTO->addedby 
                    ? (
                        $createLanguageDTO->addedby::class == User::class
                            ? $createLanguageDTO->addedby
                            : $createLanguageDTO->addedby->user
                    ) 
                    : $createLanguageDTO->user,
                'starreable' => $language,
                'type' => StarTypeEnum::contribution->value,
            ])
        );
        
        return $language;
    }
}