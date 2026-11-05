<?php

namespace App\Actions\Link;

use App\Actions\Action;
use App\DTOs\CreateLinkDTO;
use App\Enums\LinkTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Exceptions\AppException;
use App\Models\GroupTherapy;
use App\Models\Link;
use App\Models\User;

// SCRUM-383: the member invitation link $uuid, when $user may use it on $group right now -- then the
// group's page offers to join, and a private group's access check lets them see a preview of it
// (EnsureUserHasAccessToTherapyAction). Null otherwise, with no reason given: a link of another type
// or group, one that is inactive, theirs, or meant for someone else, a guest, an ended group, a
// creator paused for a missing guardian (as opening and confirming the link refuse,
// PerformGroupTherapyLinkAction), or someone already taking part.
class FindUsableGroupInvitationAction extends Action
{
    public function execute(?string $uuid, GroupTherapy $group, ?User $user): ?Link
    {
        if (! $uuid || ! $user) {
            return null;
        }

        if ($group->status === TherapyStatusEnum::ended->value || $group->creatorIsPausedForMissingGuardian(fresh: true)) {
            return null;
        }

        $link = Link::query()
            ->where('uuid', $uuid)
            ->where('type', LinkTypeEnum::groupTherapyParticipant->value)
            ->whereFor($group)
            ->first();

        if (! $link) {
            return null;
        }

        try {
            EnsureCanUseLinkAction::new()->execute(CreateLinkDTO::new()->fromArray(['user' => $user, 'link' => $link]));
        } catch (AppException) {
            return null;
        }

        return $group->isParticipant($user) ? null : $link;
    }
}
