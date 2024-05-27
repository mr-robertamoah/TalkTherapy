<?php

namespace App\Actions\Link;

use App\Actions\Action;
use App\DTOs\CreateLinkDTO;
use App\Exceptions\LinkException;

class EnsureCanUseLinkAction extends Action
{
    public function execute(CreateLinkDTO $createLinkDTO)
    {
        if (
            is_null($createLinkDTO->link->to) ||
            $createLinkDTO->link->to->is($createLinkDTO->user) ||
            $createLinkDTO->link->to->is($createLinkDTO->user->counsellor)
        ) return;

        throw new LinkException("You are not authorized to use this link.", 422);
    }
}