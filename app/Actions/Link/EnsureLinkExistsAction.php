<?php

namespace App\Actions\Link;

use App\Actions\Action;
use App\DTOs\CreateLinkDTO;
use App\Exceptions\LinkException;

class EnsureLinkExistsAction extends Action
{
    public function execute(CreateLinkDTO $createLinkDTO)
    {
        if (
            $createLinkDTO->link
        ) return;

        throw new LinkException("The link was not found.", 422);
    }
}