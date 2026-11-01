<?php

namespace App\Actions\Link;

use App\Actions\Action;
use App\Actions\Suspension\EnsureCanActAsCounsellorAction;
use App\DTOs\CreateLinkDTO;
use App\Enums\LinkTypeEnum;

class PerformLinkAction extends Action
{
    public function execute(CreateLinkDTO $createLinkDTO)
    {
        if ($createLinkDTO->link->type == LinkTypeEnum::guardianship->value) {
            return PerformGuardianshipLinkAction::new()->execute($createLinkDTO);
        }

        // SCRUM-359: both links make the person using them a therapy's or discussion's counsellor.
        if (in_array($createLinkDTO->link->type, [LinkTypeEnum::discussion->value, LinkTypeEnum::therapyCounsellor->value], true)) {
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

    }
}
