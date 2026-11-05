<?php

namespace App\Actions\Link;

use App\Actions\Action;
use App\Actions\Suspension\EnsureCanActAsCounsellorAction;
use App\DTOs\CreateLinkDTO;
use App\Enums\LinkTypeEnum;
use App\Exceptions\LinkException;

class PerformLinkAction extends Action
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
        if ($createLinkDTO->link->type == LinkTypeEnum::guardianship->value) {
            return PerformGuardianshipLinkAction::new()->execute($createLinkDTO);
        }

        if (in_array($createLinkDTO->link->type, self::COUNSELLOR_TYPES, true)) {
            EnsureCanActAsCounsellorAction::new()->execute($createLinkDTO->user?->counsellor);
        }

        if ($createLinkDTO->link->type == LinkTypeEnum::discussion->value) {
            return PerformDiscussionRequestLinkAction::new()->execute($createLinkDTO);
        }

        if ($createLinkDTO->link->type == LinkTypeEnum::therapyCounsellor->value) {
            return PerformTherapyCounsellorLinkAction::new()->execute($createLinkDTO);
        }

        if ($createLinkDTO->link->type == LinkTypeEnum::organizationSelfApply->value) {
            return PerformOrganizationSelfApplyLinkAction::new()->execute($createLinkDTO);
        }

        // SCRUM-383: every group link type, the paused-creator check included (under its locks).
        if (in_array($createLinkDTO->link->type, EnsureUserCanCreateLinkForAction::GROUP_TYPES, true)) {
            return PerformGroupTherapyLinkAction::new()->execute($createLinkDTO);
        }

        throw LinkException::inactive();
    }
}
