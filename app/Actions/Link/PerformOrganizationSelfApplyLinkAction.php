<?php

namespace App\Actions\Link;

use App\Actions\Action;
use App\DTOs\CreateLinkDTO;
use App\DTOs\OrganizationMemberRequestDTO;
use App\Enums\LinkStateEnum;
use App\Enums\LinkTypeEnum;
use App\Exceptions\LinkException;
use App\Models\Link;
use App\Models\Organization;
use App\Models\Request;
use App\Services\OrganizationMemberRequestService;
use Illuminate\Support\Facades\DB;

// SCRUM-415: an organisation join (self-apply) link, from the side of the person opening it.
//  - Opening it changes nothing: PreviewLinkAction runs the checks applying will run and shows
//    Links/Confirm with the organisation (preview()) and a "request to join" button -- or, when they can't
//    apply, why not, and nothing about the organisation (SCRUM-419; it had its own page before).
//  - Confirming (applyByLink(), from api.links.join) locks the link, checks it again, applies, and marks
//    the link used -- all in one transaction, so two people racing one link can't both use it (SCRUM-101).
// The result is the page's or the POST's own answer, never a flashed message, which another request on
// the same session could consume first.
class PerformOrganizationSelfApplyLinkAction extends Action
{
    public const APPLIED = 'Your request to join has been sent. You will be notified when an admin responds.';

    // The organisation's name, description and logo -- nothing else. The link's own checks ran first
    // (PreviewLinkAction); a refusal shows only why (SCRUM-179: one not taking applications says only that).
    public function preview(CreateLinkDTO $createLinkDTO): array
    {
        $organization = $this->organizationOf($createLinkDTO->link);

        OrganizationMemberRequestService::new()->ensureCanApplyAsMember($this->applicationFor($createLinkDTO, $organization));

        return [
            'name' => $organization->name,
            'description' => $organization->description,
            'logoUrl' => $organization->logo?->url,
        ];
    }

    public function applyByLink(CreateLinkDTO $createLinkDTO): Request
    {
        return DB::transaction(function () use ($createLinkDTO) {
            $link = Link::query()->lockForUpdate()->find($createLinkDTO->link->id);

            if (! $link || $link->state !== LinkStateEnum::active->value || $link->type !== LinkTypeEnum::organizationSelfApply->value) {
                throw LinkException::inactive();
            }

            // Every check again, under the organisation's lock (applyAsMember).
            $application = OrganizationMemberRequestService::new()->applyAsMember(
                $this->applicationFor($createLinkDTO, $this->organizationOf($link))
            );

            $link->markUsed();

            return $application;
        });
    }

    private function organizationOf(Link $link): Organization
    {
        $organization = $link->for;

        if (! $organization instanceof Organization) {
            throw LinkException::inactive();
        }

        return $organization;
    }

    private function applicationFor(CreateLinkDTO $createLinkDTO, Organization $organization): OrganizationMemberRequestDTO
    {
        return OrganizationMemberRequestDTO::new()->fromArray([
            'user' => $createLinkDTO->user,
            'organization' => $organization,
            'member' => $createLinkDTO->user,
        ]);
    }
}
