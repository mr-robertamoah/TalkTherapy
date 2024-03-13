<?php

namespace App\Actions\Profession;

use App\Actions\Action;
use App\Exceptions\ProfessionNameIsNotUniqueException;
use App\Models\Profession;

class EnsureProfessionNameIsUniqueAction extends Action
{
    public function execute(String $name) {
        if (!Profession::query()->where('name', $name)->exists()) return;

        throw new ProfessionNameIsNotUniqueException('Please use a profession name that has not already been used.', 422);
    }
}