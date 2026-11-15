<?php

namespace App\Actions\Safety;

use App\Actions\Action;
use App\Models\GroupTherapy;
use App\Models\User;
use App\Support\GroupMemberKeys;

// SCRUM-32: the members a group's counsellor can raise a safety flag for -- current and former, named
// as the group shows them (an anonymous member stays "Anonymous") and identified by their key, never
// their user id (SCRUM-345).
class GetFlaggableMembersAction extends Action
{
    public function execute(?User $viewer, ?GroupTherapy $group): array
    {
        return $this->labels(EnsureCanFlagClientAction::new()->forGroupCounsellor($viewer, $group), $viewer);
    }

    // The members as a counsellor of the group refers to them (no check: the caller has made it).
    public function labels(GroupTherapy $group, User $viewer): array
    {
        $members = GroupMemberKeys::all($group)
            ->reject(fn (array $member) => $member['user']->is($viewer) || $group->isCounsellorUser($member['user']))
            ->values();

        // Labelled by the shared rule (GroupMemberKeys::labels(): a former member by when they left, never by name).
        $labels = GroupMemberKeys::labels($group, $members);

        return $members->map(fn (array $member, int $i) => ['key' => $member['key'], 'name' => $labels[$i], 'left' => $member['left']])->all();
    }
}
