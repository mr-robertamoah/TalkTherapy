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

        // Someone who left is masked (they're no longer a member to unmask) -- told apart by when they
        // left, never by name (security review: picking the wrong person is a harm of its own).
        $labels = $members->map(fn (array $member) => $member['left'] && $group->isMaskedToOthers($member['user'])
            ? 'Former member, left '.($member['leftAt']?->format('M Y') ?? 'earlier')
            : $group->displayNameOf($member['user']));
        $counts = $labels->countBy();
        $seen = [];

        return $members->map(function (array $member, int $i) use ($labels, $counts, &$seen) {
            $label = $labels[$i];
            if ($counts[$label] > 1) {
                $seen[$label] = ($seen[$label] ?? 0) + 1;
                $label .= " ({$seen[$label]})";
            }

            return ['key' => $member['key'], 'name' => $label, 'left' => $member['left']];
        })->all();
    }
}
