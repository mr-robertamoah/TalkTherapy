<?php

namespace App\Actions\Link;

use App\Actions\Action;
use App\DTOs\CreateLinkDTO;
use App\Enums\LinkTypeEnum;
use App\Exceptions\LinkException;

class EnsureCanUpdateLinkAction extends Action
{
    public function execute(CreateLinkDTO $createLinkDTO)
    {
        if (
            $createLinkDTO->user->isAdmin() || 
            $createLinkDTO->user->is($createLinkDTO->link->addedby) || 
            (
                $createLinkDTO->user->counsellor && 
                $createLinkDTO->user->counsellor->is($createLinkDTO->link->addedby)
            )
        ) return;

        throw new LinkException("The link should be for something and must have a valid type.", 422);
    }
}