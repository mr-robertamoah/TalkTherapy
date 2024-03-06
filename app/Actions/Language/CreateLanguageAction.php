<?php

namespace App\Actions\Language;

use App\Actions\Action;
use App\DTOs\CreateLanguageDTO;

class CreateLanguageAction extends Action
{
    public function execute(CreateLanguageDTO $createLanguageDTO) {
        $addedby = $createLanguageDTO->addedby ? $createLanguageDTO->addedby : $createLanguageDTO->user;

        return $addedby->addedLanguages()->create(
            $createLanguageDTO->getData(true)
        );
    }
}