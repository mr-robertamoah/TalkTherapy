<?php

namespace App\Actions\Link;

use App\Actions\Action;
use App\Actions\GroupTherapy\AddCounsellorToGroupTherapyAction;
use App\DTOs\CreateLinkDTO;
use App\Enums\LinkTypeEnum;
use App\Exceptions\GuardianRequiredException;
use App\Exceptions\LinkException;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\Link;
use App\Models\Organization;
use App\Models\Therapy;
use App\Models\User;

// SCRUM-350: who may create a link for a given target. Before this, nothing checked it: anyone could
// create a GUARDIANSHIP link for any user (whoever opened it became their guardian), a
// THERAPY_COUNSELLOR link for someone else's therapy, or a DISCUSSION link for any discussion.
// Mirrors the one place the app creates each type. Every refusal -- including a target that doesn't
// exist -- is the same 403, so link creation can't be used to probe ids. ORGANIZATION_SELF_APPLY
// has its own check (EnsureUserCanCreateOrganizationSelfApplyLinkAction).
class EnsureUserCanCreateLinkForAction extends Action
{
    public const NOT_ALLOWED = 'You are not allowed to create this link.';

    public const GROUP_TYPES = [
        LinkTypeEnum::groupTherapyAdmin->value,
        LinkTypeEnum::groupTherapyParticipant->value,
        LinkTypeEnum::groupTherapyCounsellor->value,
    ];

    public function execute(CreateLinkDTO $createLinkDTO): void
    {
        if ($createLinkDTO->type === LinkTypeEnum::organizationSelfApply->value) {
            return;
        }

        // Security review: the link's stated creator must be the requester (or their counsellor
        // profile) -- otherwise an admin, whom EnsureAddedbyIsValidAction lets name any addedby,
        // could issue a link that appears to come from someone else.
        $addedby = $createLinkDTO->addedby;
        $isOwnAccount = $addedby && $createLinkDTO->user
            && ($addedby->is($createLinkDTO->user) || $addedby->is($createLinkDTO->user->counsellor));

        if (! $isOwnAccount || ! $this->mayCreate($createLinkDTO->user, $createLinkDTO->type, $createLinkDTO->for)) {
            throw new LinkException(self::NOT_ALLOWED, 403);
        }

        // SCRUM-288: a paused minor can't invite a counsellor into their therapy (using the link is
        // refused too, PerformTherapyCounsellorLinkAction). SCRUM-382: nor anyone into a group they
        // created (using one is refused too, PerformLinkAction). A group created through a
        // counsellor profile is the counsellor's, who is never the minor it is paused for.
        $intoUsersGroup = in_array($createLinkDTO->type, self::GROUP_TYPES, true) && $createLinkDTO->for->addedby_type === User::class;
        $invitesIntoOwnTherapy = $createLinkDTO->type === LinkTypeEnum::therapyCounsellor->value || $intoUsersGroup;

        if ($invitesIntoOwnTherapy && $createLinkDTO->user->isPausedForMissingGuardian()) {
            throw GuardianRequiredException::forViewer();
        }

        // SCRUM-382 review: and nobody else into a group whose creator is paused -- the same rule
        // using a link follows (PerformLinkAction). Only the creator may create group links today
        // (mayCreate()), so this guards that rule changing (e.g. group co-admins).
        if ($intoUsersGroup && $createLinkDTO->for->addedby instanceof User && $createLinkDTO->for->addedby->isPausedForMissingGuardian()) {
            throw GuardianRequiredException::linkUnavailable();
        }

        // SCRUM-383 (QA): a counsellor link for a group whose counsellor places are all taken would
        // only be refused when used -- refused now, with the same answer.
        if (
            $createLinkDTO->type === LinkTypeEnum::groupTherapyCounsellor->value
            && ! $createLinkDTO->for->hasFreeCounsellorPlace()
        ) {
            throw new LinkException(AddCounsellorToGroupTherapyAction::GROUP_FULL, 422);
        }
    }

    // Security review: re-checked when a link is USED (EnsureCanUseLinkAction). Links created before
    // SCRUM-350 were never checked, and a creator can re-activate their own link, so creation-time
    // checks alone would leave every old malicious link working -- and a link outliving its
    // creator's authority (e.g. a discussion they no longer manage).
    public static function creatorMayStillIssue(Link $link): bool
    {
        $creator = $link->addedby instanceof Counsellor ? $link->addedby->user : $link->addedby;

        if (! $creator instanceof User) {
            return false;
        }

        if ($link->type === LinkTypeEnum::organizationSelfApply->value) {
            return $link->for instanceof Organization && $link->for->isAdministeredBy($creator);
        }

        return (new self)->mayCreate($creator, $link->type, $link->for);
    }

    private function mayCreate(?User $user, ?string $type, mixed $for): bool
    {
        if (! $user || ! $for) {
            return false;
        }

        return match ($type) {
            // AddGuardianModal: a user invites someone to be *their own* guardian.
            LinkTypeEnum::guardianship->value => $for instanceof User && $for->is($user),
            // The therapy page: the client invites a counsellor to their own therapy.
            LinkTypeEnum::therapyCounsellor->value => $for instanceof Therapy && $for->isUser($user),
            // The group page: its creator (a user, or a counsellor's user). SCRUM-383 (the user's
            // decision): no GROUP_THERAPY_ADMIN -- the app has no group-admin role. Refused here, and
            // so (creatorMayStillIssue()) when an existing one is used.
            LinkTypeEnum::groupTherapyParticipant->value,
            LinkTypeEnum::groupTherapyCounsellor->value => $for instanceof GroupTherapy && $this->createdGroup($user, $for),
            // DiscussionModal: whoever may manage the discussion.
            LinkTypeEnum::discussion->value => $for instanceof Discussion && $for->isManageableBy($user),
            default => false,
        };
    }

    private function createdGroup(User $user, GroupTherapy $group): bool
    {
        return $group->isCreatedBy($user);
    }
}
