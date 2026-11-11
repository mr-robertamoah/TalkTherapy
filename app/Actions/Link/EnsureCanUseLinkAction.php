<?php

namespace App\Actions\Link;

use App\Actions\Action;
use App\Actions\Suspension\EnsureCanActAsCounsellorAction;
use App\DTOs\CreateLinkDTO;
use App\Enums\LinkStateEnum;
use App\Enums\LinkTypeEnum;
use App\Exceptions\LinkException;

class EnsureCanUseLinkAction extends Action
{
    // SCRUM-359/383: the links that make the person using them a counsellor of something -- a
    // discussion, a 1:1 therapy, or a group's team. A suspended counsellor can't take on work.
    public const COUNSELLOR_TYPES = [
        LinkTypeEnum::discussion->value,
        LinkTypeEnum::therapyCounsellor->value,
        LinkTypeEnum::groupTherapyCounsellor->value,
    ];

    public function execute(CreateLinkDTO $createLinkDTO)
    {
        // A link is deactivated (see PerformLinkAction's siblings) once it has been used to
        // successfully assign/attach something, so it can't be replayed indefinitely by
        // whoever still holds the URL -- SCRUM-101.
        if ($createLinkDTO->link->state !== LinkStateEnum::active->value) {
            throw new LinkException('This link is no longer active.', 422);
        }

        // SCRUM-350 (security review): its creator must still be allowed to issue it -- same answer
        // as an inactive link.
        if (! EnsureUserCanCreateLinkForAction::creatorMayStillIssue($createLinkDTO->link)) {
            throw new LinkException('This link is no longer active.', 422);
        }

        if (
            $createLinkDTO->link->addedby->is($createLinkDTO->user) ||
            $createLinkDTO->link->addedby->is($createLinkDTO->user->counsellor)
        ) {
            throw new LinkException('You cannot use a link you created.', 422);
        }

        // SCRUM-418: here, so opening a link and confirming it both check it (it used to be checked only
        // on opening).
        if (in_array($createLinkDTO->link->type, self::COUNSELLOR_TYPES, true)) {
            EnsureCanActAsCounsellorAction::new()->execute($createLinkDTO->user?->counsellor);
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
