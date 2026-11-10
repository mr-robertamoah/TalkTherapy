<?php

namespace App\Actions\Link;

use App\Actions\Action;
use App\DTOs\CreateLinkDTO;
use App\DTOs\OrganizationMemberRequestDTO;
use App\Enums\LinkStateEnum;
use App\Exceptions\LinkException;
use App\Models\Link;
use App\Services\OrganizationMemberRequestService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;

class PerformOrganizationSelfApplyLinkAction extends Action
{
    public function execute(CreateLinkDTO $createLinkDTO)
    {
        DB::transaction(function () use ($createLinkDTO) {
            // A general link (to=null) can be used by any user, so locking the link row (not
            // just relying on OrganizationMemberRequestService's own pending-request check)
            // closes the same replay race SCRUM-101 fixed for the other link types: two
            // concurrent uses of this same link could otherwise both read state=active and both
            // apply before either commits deactivate().
            $link = Link::query()->lockForUpdate()->findOrFail($createLinkDTO->link->id);

            if ($link->state !== LinkStateEnum::active->value) {
                throw new LinkException('This link is no longer active.', 422);
            }

            // Reuses TT-6.3a's existing self-apply eligibility checks (organization verified,
            // consumer-capable, self_apply_enabled) and pending-request guard unchanged (SCRUM-164
            // AC2) -- this is the same service call OrganizationMemberController::apply() makes,
            // just reached via a link instead of a direct POST.
            OrganizationMemberRequestService::new()->applyAsMember(
                OrganizationMemberRequestDTO::new()->fromArray([
                    'user' => $createLinkDTO->user,
                    'organization' => $createLinkDTO->link->for,
                    'member' => $createLinkDTO->user,
                ])
            );

            // Deactivating on successful use (SCRUM-101) keeps this link from being replayed by
            // whoever still holds the URL -- safe from the race described above because it
            // happens inside the same lockForUpdate transaction as the active-state check.
            $link->deactivate();
        });

        return Redirect::route('home');
    }
}
