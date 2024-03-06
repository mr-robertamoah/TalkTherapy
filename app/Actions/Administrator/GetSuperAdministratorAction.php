<?php

namespace App\Actions\Administrator;
use App\Actions\Action;
use App\Enums\AdministratorTypeEnum;
use App\Models\Administrator;

class GetSuperAdministratorAction extends Action
{
    public function execute()
    {
        return Administrator::query()
            ->where('type', AdministratorTypeEnum::super->value)
            ->first();
    }
}