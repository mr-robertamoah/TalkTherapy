<?php

namespace App\Actions\Link;

use App\Actions\Action;
use App\DTOs\CreateLinkDTO;

class DeleteLinkAction extends Action
{
    public function execute(CreateLinkDTO $createLinkDTO)
    {
        return $createLinkDTO->link->delete();
    }
}