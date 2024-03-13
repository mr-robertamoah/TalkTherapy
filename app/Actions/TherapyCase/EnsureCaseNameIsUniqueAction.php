<?php

namespace App\Actions\TherapyCase;
use App\Actions\Action;
use App\Exceptions\CaseNameIsNotUniqueException;
use App\Models\TherapyCase;

class EnsureCaseNameIsUniqueAction extends Action
{
    public function execute(String $name) {
        if (!TherapyCase::query()->where('name', $name)->exists()) return;

        throw new CaseNameIsNotUniqueException('Please use a case name that has not already been used.', 422);
    }
}