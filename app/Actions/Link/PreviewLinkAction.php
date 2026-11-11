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
// counsellor -- changes nothing. It runs the checks confirming will run (EnsureCanUseLinkAction and the
// action's own) and shows Links/Confirm: what they'd be agreeing to, or why they can't (a refusal and
// nothing else -- never what the link was for). Confirming is api.links.join (LinkService::confirmLink).
class PreviewLinkAction extends Action
{
    public const TYPES = [
        LinkTypeEnum::guardianship->value,
        LinkTypeEnum::therapyCounsellor->value,
        LinkTypeEnum::discussion->value,
        LinkTypeEnum::groupTherapyCounsellor->value,
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
                default => throw LinkException::inactive(),
            };
        } catch (AppException $exception) {
            return Inertia::render('Links/Confirm', ['refusal' => $exception->getMessage()]);
        }

        return Inertia::render('Links/Confirm', [
            'uuid' => $createLinkDTO->link->uuid,
            'type' => $createLinkDTO->link->type,
            'summary' => $summary,
        ]);
    }
}
