<?php

namespace App\Actions\Star;
use App\Actions\Action;
use App\Enums\StarTypeEnum;
use App\Exceptions\StarTypeIsInvalidException;

class EnsureStarTypeIsValidAction extends Action
{
    public function execute(String $type)
    {
        if (in_array(strtoupper($type), StarTypeEnum::values())) return;

        throw new StarTypeIsInvalidException("{$type} is not a valid star type.");
    }
}