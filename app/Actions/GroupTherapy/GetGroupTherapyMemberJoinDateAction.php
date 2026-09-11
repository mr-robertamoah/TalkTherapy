<?php

namespace App\Actions\GroupTherapy;

use App\Actions\Action;
use App\Models\GroupTherapy;
use App\Models\User;
use Illuminate\Support\Carbon;

// TT-7.5b-b3/SCRUM-267: resolves the one timestamp the late-joiner "historical content" exemption
// compares against -- when a User-type creator directly created this group (TT-7.5b-b2's own
// membership-ambiguity fix left them with no `group_therapy_user` pivot row at all, matching
// GroupTherapy::getUsers()'s existing addedby-is-implicit-member convention), their own join date
// IS the group's own creation date, not a pivot row that doesn't exist for them.
class GetGroupTherapyMemberJoinDateAction extends Action
{
    public function execute(GroupTherapy $groupTherapy, User $user): ?Carbon
    {
        $groupTherapy->loadMissing('users');

        $pivotMember = $groupTherapy->users->firstWhere('id', $user->id);
        if ($pivotMember) {
            return $pivotMember->pivot->created_at;
        }

        if ($groupTherapy->addedby_type === User::class && $groupTherapy->addedby_id === $user->id) {
            return $groupTherapy->created_at;
        }

        // Not actually a member (e.g. a counsellor, or an unrelated user) -- callers must only
        // ever reach this for someone already confirmed to be a member.
        return null;
    }
}
