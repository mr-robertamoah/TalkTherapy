<?php

namespace App\Actions\CounsellorVerification;

use App\Actions\Action;
use App\Exceptions\CounsellorException;
use App\Models\Counsellor;
use App\Models\GroupTherapy;

// SCRUM-30/31 (the user's decisions): only a currently verified counsellor takes on new work --
// accepting or offering to assist a therapy, using a therapy's counsellor link, starting a group as
// its counsellor, being asked to ($asTarget: a client inviting them), and joining or starting a
// discussion (forDiscussion). Never verified, ended and revoked alike. Work already under way never
// asks (sessions, messages, the clients and discussions they have). A group run only by counsellors
// who aren't currently verified admits no new members (ensureGroupMayAdmit()).
class EnsureCounsellorMayTakeOnClientsAction extends Action
{
    public const NOT_VERIFIED = 'Your verification isn\'t current, so you can\'t take on new clients. You can ask to be verified from your profile.';

    public const TARGET_NOT_VERIFIED = 'This counsellor isn\'t verified at the moment, so they can\'t take on new clients.';

    public const NOT_VERIFIED_FOR_DISCUSSION = 'Your verification isn\'t current, so you can\'t join or start discussions. You can ask to be verified from your profile.';

    public const TARGET_NOT_VERIFIED_FOR_DISCUSSION = 'This counsellor isn\'t verified at the moment, so they can\'t join discussions.';

    public const GROUP_NOT_ADMITTING = 'This group isn\'t taking new members at the moment, because its counsellor\'s verification isn\'t current.';

    // Null (no counsellor involved, e.g. a group a user started) passes: callers that need one
    // refuse it themselves.
    public function execute(?Counsellor $counsellor, bool $asTarget = false, bool $forDiscussion = false): void
    {
        if (! $counsellor || $counsellor->isVerified()) {
            return;
        }

        $message = match (true) {
            $forDiscussion && $asTarget => self::TARGET_NOT_VERIFIED_FOR_DISCUSSION,
            $forDiscussion => self::NOT_VERIFIED_FOR_DISCUSSION,
            $asTarget => self::TARGET_NOT_VERIFIED,
            default => self::NOT_VERIFIED,
        };

        throw new CounsellorException($message, 422);
    }

    // A group that has counsellors (its creator, if a counsellor, and its active counsellors) but none
    // of them currently verified admits nobody new. One still waiting for its first counsellor does.
    public function ensureGroupMayAdmit(GroupTherapy $group): void
    {
        if (! $group->admitsNewMembers()) {
            throw new CounsellorException(self::GROUP_NOT_ADMITTING, 422);
        }
    }
}
