<?php

namespace App\Actions\Language;

use App\Actions\Action;
use App\Exceptions\LanguageNameIsNotUniqueException;
use App\Models\Language;

class EnsureLanguageNameIsUniqueAction extends Action
{
    public function execute(String $name) {
        if (!Language::query()->where('name', $name)->exists()) return;

        throw new LanguageNameIsNotUniqueException('Please use a language name that has not already been used.');
    }
}