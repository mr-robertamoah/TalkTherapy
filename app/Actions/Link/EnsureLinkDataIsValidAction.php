<?php

namespace App\Actions\Link;

use App\Actions\Action;
use App\DTOs\CreateLinkDTO;
use App\Enums\LinkTypeEnum;
use App\Exceptions\LinkException;

class EnsureLinkDataIsValidAction extends Action
{
    public function execute(CreateLinkDTO $createLinkDTO)
    {
        if (
            $createLinkDTO->for && 
            $createLinkDTO->type && 
            in_array($createLinkDTO->type, LinkTypeEnum::values())
        ) return;

        throw new LinkException("The link should be for something and must have a valid type.", 422);
    }
}