<?php

namespace App\Actions\Link;

use App\Actions\Action;
use App\DTOs\CreateLinkDTO;
use App\Enums\LinkTypeEnum;
use App\Exceptions\AppException;
use App\Exceptions\LinkException;
use Inertia\Inertia;
use Inertia\Response;

// SCRUM-418: opening a link that acts -- guardianship, a therapy's counsellor, a discussion, a group's
// counsellor, and (SCRUM-419) an organisation join link -- changes nothing. It runs the checks confirming
// will run (EnsureCanUseLinkAction and the action's own) and shows Links/Confirm: what they'd be agreeing
// to, or why they can't (a refusal and nothing else -- never what the link was for, except where the page
// already said it: REFUSAL_NAMES_TYPE). Confirming is api.links.join (LinkService::confirmLink).
class PreviewLinkAction extends Action
{
    public const TYPES = [
        LinkTypeEnum::guardianship->value,
        LinkTypeEnum::therapyCounsellor->value,
        LinkTypeEnum::discussion->value,
        LinkTypeEnum::groupTherapyCounsellor->value,
        LinkTypeEnum::organizationSelfApply->value,
    ];

    // A refused join link has always said it was a join link ("This join link can't be used", and the way
    // back to My organizations, SCRUM-415) -- the type, and nothing about the organisation.
    public const REFUSAL_NAMES_TYPE = [
        LinkTypeEnum::organizationSelfApply->value,
    ];

    public function execute(CreateLinkDTO $createLinkDTO): Response
    {
        try {
            EnsureCanUseLinkAction::new()->execute($createLinkDTO);

            $summary = match ($createLinkDTO->link->type) {
                LinkTypeEnum::guardianship->value => PerformGuardianshipLinkAction::new()->preview($createLinkDTO),
                LinkTypeEnum::therapyCounsellor->value => PerformTherapyCounsellorLinkAction::new()->preview($createLinkDTO),
                LinkTypeEnum::discussion->value => PerformDiscussionRequestLinkAction::new()->preview($createLinkDTO),
                LinkTypeEnum::groupTherapyCounsellor->value => PerformGroupTherapyLinkAction::new()->previewAsCounsellor($createLinkDTO),
                LinkTypeEnum::organizationSelfApply->value => PerformOrganizationSelfApplyLinkAction::new()->preview($createLinkDTO),
                default => throw LinkException::inactive(),
            };
        } catch (AppException $exception) {
            return Inertia::render('Links/Confirm', [
                'refusal' => $exception->getMessage(),
                ...(in_array($createLinkDTO->link->type, self::REFUSAL_NAMES_TYPE, true) ? ['type' => $createLinkDTO->link->type] : []),
            ]);
        }

        return Inertia::render('Links/Confirm', [
            'uuid' => $createLinkDTO->link->uuid,
            'type' => $createLinkDTO->link->type,
            'summary' => $summary,
        ]);
    }
}
