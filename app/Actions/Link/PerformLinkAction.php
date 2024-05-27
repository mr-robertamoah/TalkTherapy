<?php

namespace App\Actions\Link;

use App\Actions\Action;
use App\DTOs\CreateLinkDTO;
use App\Enums\LinkTypeEnum;

class PerformLinkAction extends Action
{
    public function execute(CreateLinkDTO $createLinkDTO)
    {
        if ($createLinkDTO->link->type == LinkTypeEnum::guardianship->value)
            return PerformGuardianshipLinkAction::new()->execute($createLinkDTO);
        
        if ($createLinkDTO->link->type == LinkTypeEnum::therapyCounsellor->value)
            return PerformTherapyCounsellorLinkAction::new()->execute($createLinkDTO);
        
    }
}