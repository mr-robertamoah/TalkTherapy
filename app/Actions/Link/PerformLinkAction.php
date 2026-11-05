<?php

namespace App\Actions\Link;

use App\Actions\Action;
use App\Actions\Suspension\EnsureCanActAsCounsellorAction;
use App\Actions\Therapy\EnsureCanCreateTherapyAction;
use App\DTOs\CreateLinkDTO;
use App\Enums\LinkTypeEnum;
use App\Exceptions\GuardianRequiredException;
use App\Models\GroupTherapy;
use App\Models\User;

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

        if (in_array($createLinkDTO->link->type, EnsureUserCanCreateLinkForAction::GROUP_TYPES, true)) {
            $this->ensureGroupLinkCanBeUsed($createLinkDTO);
        }
    }

    // SCRUM-382: nobody is let into (or onto the team of) a group whose creator is a minor paused
    // for a missing guardian -- told neutrally, as for a request (the person using it isn't that
    // minor's counsellor). And a paused minor can't join someone else's group by link, the same
    // refusal as joining it from its page (EnsureCanCreateTherapyAction). A counsellor link makes
    // its user a counsellor, whose own age isn't asked (EnsureMinorHasActiveGuardianAction).
    private function ensureGroupLinkCanBeUsed(CreateLinkDTO $createLinkDTO): void
    {
        $group = $createLinkDTO->link->for;
        $creator = $group instanceof GroupTherapy && $group->addedby instanceof User ? $group->addedby : null;

        if ($creator?->isPausedForMissingGuardian(fresh: true)) {
            throw GuardianRequiredException::linkUnavailable();
        }

        if ($createLinkDTO->link->type !== LinkTypeEnum::groupTherapyCounsellor->value) {
            EnsureCanCreateTherapyAction::new()->execute($createLinkDTO->user);
        }
    }
}
