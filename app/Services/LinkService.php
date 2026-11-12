<?php

namespace App\Services;

use App\Actions\EnsureAddedbyIsValidAction;
use App\Actions\Link\ChangeLinkStatusAction;
use App\Actions\Link\CreateLinkAction;
use App\Actions\Link\DeleteLinkAction;
use App\Actions\Link\EnsureCanUpdateLinkAction;
use App\Actions\Link\EnsureCanUseLinkAction;
use App\Actions\Link\EnsureLinkDataIsValidAction;
use App\Actions\Link\EnsureLinkExistsAction;
use App\Actions\Link\EnsureUserCanCreateLinkForAction;
use App\Actions\Link\EnsureUserCanCreateOrganizationSelfApplyLinkAction;
use App\Actions\Link\PerformDiscussionRequestLinkAction;
use App\Actions\Link\PerformGroupTherapyLinkAction;
use App\Actions\Link\PerformGuardianshipLinkAction;
use App\Actions\Link\PerformLinkAction;
use App\Actions\Link\PerformOrganizationSelfApplyLinkAction;
use App\Actions\Link\PerformTherapyCounsellorLinkAction;
use App\Actions\Link\PreviewLinkAction;
use App\Actions\User\EnsureUserExistsAction;
use App\DTOs\CreateLinkDTO;
use App\DTOs\GetLinksDTO;
use App\Enums\LinkTypeEnum;
use App\Enums\PaginationEnum;
use App\Exceptions\LinkException;
use App\Http\Resources\LinkResource;
use App\Models\Link;
use App\Support\LinkConfirmation;
use App\Support\TherapyLink;
use Illuminate\Support\Facades\DB;

class LinkService extends Service
{
    public function performAction(CreateLinkDTO $createLinkDTO)
    {
        EnsureUserExistsAction::new()->execute($createLinkDTO->user);

        EnsureLinkExistsAction::new()->execute($createLinkDTO);

        // SCRUM-418/419: the links that act are shown first, and used only by confirming.
        if (in_array($createLinkDTO->link->type, PreviewLinkAction::TYPES, true)) {
            return PreviewLinkAction::new()->execute($createLinkDTO);
        }

        EnsureCanUseLinkAction::new()->execute($createLinkDTO);

        return PerformLinkAction::new()->execute($createLinkDTO);
    }

    // Confirming a link that opening only showed: a group's member invitation (SCRUM-383 -- on the
    // group's page, with their anonymity choice), an organisation join link (SCRUM-415), and the links
    // that act (SCRUM-418). What happened, and where to go next.
    public function confirmLink(CreateLinkDTO $createLinkDTO): LinkConfirmation
    {
        EnsureUserExistsAction::new()->execute($createLinkDTO->user);

        EnsureLinkExistsAction::new()->execute($createLinkDTO);

        EnsureCanUseLinkAction::new()->execute($createLinkDTO);

        return match ($createLinkDTO->link->type) {
            LinkTypeEnum::groupTherapyParticipant->value => new LinkConfirmation(
                PerformGroupTherapyLinkAction::JOINED,
                TherapyLink::to(PerformGroupTherapyLinkAction::new()->joinAsMember($createLinkDTO)),
            ),
            LinkTypeEnum::organizationSelfApply->value => $this->applyByLink($createLinkDTO),
            LinkTypeEnum::guardianship->value => PerformGuardianshipLinkAction::new()->execute($createLinkDTO),
            LinkTypeEnum::therapyCounsellor->value => PerformTherapyCounsellorLinkAction::new()->execute($createLinkDTO),
            LinkTypeEnum::discussion->value => PerformDiscussionRequestLinkAction::new()->execute($createLinkDTO),
            LinkTypeEnum::groupTherapyCounsellor->value => PerformGroupTherapyLinkAction::new()->joinAsCounsellor($createLinkDTO),
            default => throw LinkException::inactive(),
        };
    }

    private function applyByLink(CreateLinkDTO $createLinkDTO): LinkConfirmation
    {
        PerformOrganizationSelfApplyLinkAction::new()->applyByLink($createLinkDTO);

        return new LinkConfirmation(PerformOrganizationSelfApplyLinkAction::APPLIED, route('organizations.mine.dashboard'));
    }

    public function createLink(CreateLinkDTO $createLinkDTO)
    {
        EnsureUserExistsAction::new()->execute($createLinkDTO->user);

        EnsureAddedbyIsValidAction::new()->execute(dto: $createLinkDTO, force: true);

        // Runs before EnsureLinkDataIsValidAction for this type specifically -- a nonexistent
        // organization (for = null) and an existing one the caller doesn't administer both hit
        // this same check first, collapsing them into one indistinguishable 403 rather than
        // leaking organization-id existence via 422-vs-403 (security review, SCRUM-164).
        EnsureUserCanCreateOrganizationSelfApplyLinkAction::new()->execute($createLinkDTO);

        EnsureUserCanCreateLinkForAction::new()->execute($createLinkDTO);

        EnsureLinkDataIsValidAction::new()->execute($createLinkDTO);

        return CreateLinkAction::new()->execute($createLinkDTO);
    }

    public function changeLinkStatus(CreateLinkDTO $createLinkDTO)
    {
        EnsureUserExistsAction::new()->execute($createLinkDTO->user);

        EnsureLinkExistsAction::new()->execute($createLinkDTO);

        EnsureCanUpdateLinkAction::new()->execute($createLinkDTO);

        return ChangeLinkStatusAction::new()->execute($createLinkDTO);
    }

    public function changeMultipleLinkStatuses(CreateLinkDTO $createLinkDTO)
    {
        EnsureUserExistsAction::new()->execute($createLinkDTO->user);

        // SCRUM-415: all or none -- one link that can't be switched back on leaves the others as they were.
        return DB::transaction(function () use ($createLinkDTO) {
            $links = [];

            foreach ($createLinkDTO->linksData as $data) {
                $dto = CreateLinkDTO::new()->fromArray([
                    'user' => $createLinkDTO->user,
                    'link' => Link::find($data['id']),
                ]);

                EnsureLinkExistsAction::new()->execute($dto);

                EnsureCanUpdateLinkAction::new()->execute($dto);

                $links[] = ChangeLinkStatusAction::new()->execute($dto);
            }

            return $links;
        });
    }

    public function deleteLink(CreateLinkDTO $createLinkDTO)
    {
        EnsureUserExistsAction::new()->execute($createLinkDTO->user);

        EnsureLinkExistsAction::new()->execute($createLinkDTO);

        EnsureCanUpdateLinkAction::new()->execute($createLinkDTO);

        return DeleteLinkAction::new()->execute($createLinkDTO);
    }

    public function deleteMultipleLinks(CreateLinkDTO $createLinkDTO)
    {
        EnsureUserExistsAction::new()->execute($createLinkDTO->user);

        // All or none, like changeMultipleLinkStatuses().
        DB::transaction(function () use ($createLinkDTO) {
            foreach ($createLinkDTO->linksData as $data) {
                $dto = CreateLinkDTO::new()->fromArray([
                    'user' => $createLinkDTO->user,
                    'link' => Link::find($data['id']),
                ]);

                EnsureLinkExistsAction::new()->execute($dto);

                EnsureCanUpdateLinkAction::new()->execute($dto);

                DeleteLinkAction::new()->execute($dto);
            }
        });
    }

    public function getLinks(GetLinksDTO $getLinksDTO)
    {
        if (
            $getLinksDTO->addedby &&
            ! $getLinksDTO->addedby->is($getLinksDTO->user) &&
            ! $getLinksDTO->addedby->is($getLinksDTO->user?->counsellor)
        ) {
            return [];
        }

        $query = Link::query();

        if ($getLinksDTO->addedby) {
            $query->whereAddedby($getLinksDTO->addedby);
        } elseif ($getLinksDTO->user) {
            $query->whereAddedby($getLinksDTO->user);
        }

        if ($getLinksDTO->for) {
            $query->whereFor($getLinksDTO->for);
        }

        if ($getLinksDTO->to) {
            $query->whereTo($getLinksDTO->to);
        }

        if ($getLinksDTO->state) {
            $query->whereState($getLinksDTO->state);
        }

        if ($getLinksDTO->type) {
            $query->whereType($getLinksDTO->type);
        }

        return LinkResource::collection(
            $query->latest()->paginate(PaginationEnum::preferencesPagination->value)
        );
    }
}
