<?php

namespace App\Actions\Religion;

use App\Actions\Action;
use App\Exceptions\ReligionNameIsNotUniqueException;
use App\Models\Religion;

class EnsureReligionNameIsUniqueAction extends Action
{
    public function execute(String $name) {
        if (!Religion::query()->where('name', $name)->exists()) return;

        throw new ReligionNameIsNotUniqueException('Please use a religion name that has not already been used.', 422);
    }
}