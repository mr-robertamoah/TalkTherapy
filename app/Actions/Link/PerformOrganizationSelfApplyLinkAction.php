<?php

namespace App\Actions\Link;

use App\Actions\Action;
use App\DTOs\CreateLinkDTO;
use App\DTOs\OrganizationMemberRequestDTO;
use App\Enums\LinkStateEnum;
use App\Enums\LinkTypeEnum;
use App\Exceptions\AppException;
use App\Exceptions\LinkException;
use App\Models\Link;
use App\Models\Organization;
use App\Models\Request;
use App\Services\OrganizationMemberRequestService;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

// SCRUM-415: an organisation join (self-apply) link, from the side of the person opening it.
//  - Opening it (preview()) changes nothing: it runs the checks applying will run and shows a page with
//    the organisation and a "Request to join" button -- or, when they can't apply, why not, and nothing
//    about the organisation (SCRUM-179: an organisation not taking applications says only that).
//  - Confirming (applyByLink(), from api.links.join) locks the link, checks it again, applies, and marks
//    the link used -- all in one transaction, so two people racing one link can't both use it (SCRUM-101).
// The result is the page's or the POST's own answer, never a flashed message, which another request on
// the same session could consume first.
class PerformOrganizationSelfApplyLinkAction extends Action
{
    public const APPLIED = 'Your request to join has been sent. You will be notified when an admin responds.';

    public function preview(CreateLinkDTO $createLinkDTO): Response
    {
        try {
            EnsureCanUseLinkAction::new()->execute($createLinkDTO);

            $organization = $this->organizationOf($createLinkDTO->link);

            OrganizationMemberRequestService::new()->ensureCanApplyAsMember($this->applicationFor($createLinkDTO, $organization));
        } catch (AppException $exception) {
            return Inertia::render('Links/OrganizationJoin', ['refusal' => $exception->getMessage()]);
        }

        return Inertia::render('Links/OrganizationJoin', [
            'uuid' => $createLinkDTO->link->uuid,
            'organization' => [
                'name' => $organization->name,
                'description' => $organization->description,
                'logoUrl' => $organization->logo?->url,
            ],
        ]);
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
