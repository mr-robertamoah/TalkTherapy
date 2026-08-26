<?php

namespace App\Actions\Link;

use App\Actions\Action;
use App\DTOs\CreateLinkDTO;
use App\Enums\LinkStateEnum;
use App\Exceptions\LinkException;

class EnsureCanUseLinkAction extends Action
{
    public function execute(CreateLinkDTO $createLinkDTO)
    {
        // A link is deactivated (see PerformLinkAction's siblings) once it has been used to
        // successfully assign/attach something, so it can't be replayed indefinitely by
        // whoever still holds the URL -- SCRUM-101.
        if ($createLinkDTO->link->state !== LinkStateEnum::active->value) {
            throw new LinkException('This link is no longer active.', 422);
        }

        if (
            $createLinkDTO->link->addedby->is($createLinkDTO->user) ||
            $createLinkDTO->link->addedby->is($createLinkDTO->user->counsellor)
        ) {
            throw new LinkException('You cannot use a link you created.', 422);
        }

        if (
            is_null($createLinkDTO->link->to) ||
            $createLinkDTO->link->to->is($createLinkDTO->user) ||
            $createLinkDTO->link->to->is($createLinkDTO->user->counsellor)
        ) {
            return;
        }

        throw new LinkException('You are not authorized to use this link.', 422);
    }
}
