<?php

namespace App\Actions\Link;

use App\Actions\Action;
use App\DTOs\CreateLinkDTO;
use App\Enums\LinkStateEnum;

class ChangeLinkStatusAction extends Action
{
    public function execute(CreateLinkDTO $createLinkDTO)
    {
        if ($createLinkDTO->link->state == LinkStateEnum::active->value)
            $createLinkDTO->link->deactivate();
        else
            $createLinkDTO->link->activate();

        return $createLinkDTO->link->refresh();
    }
}