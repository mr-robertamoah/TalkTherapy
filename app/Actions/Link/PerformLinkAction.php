<?php

namespace App\Actions\Link;

use App\Actions\Action;
use App\DTOs\CreateLinkDTO;
use App\Exceptions\LinkException;

// Opening a link that isn't shown first (PreviewLinkAction, SCRUM-418/419): only a group's links are left -- a member invitation goes to the group's page to confirm
// there (SCRUM-383); the switched-off admin type is told it's inactive. LinkService has checked the link.
class PerformLinkAction extends Action
{
    public function execute(CreateLinkDTO $createLinkDTO)
    {
        if (in_array($createLinkDTO->link->type, EnsureUserCanCreateLinkForAction::GROUP_TYPES, true)) {
            return PerformGroupTherapyLinkAction::new()->execute($createLinkDTO);
        }

        throw LinkException::inactive();
    }
}
