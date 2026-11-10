<?php

namespace App\Actions\Guardianship;

use App\Actions\Action;
use App\Enums\TherapyStatusEnum;
use App\Models\GroupTherapy;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Support\Collection;

// SCRUM-288: what a guardian loss (or return) touches -- the minor's individual therapies that
// haven't ended (with their counsellor), and the groups they created or belong to that haven't.
class GetWardOpenTherapiesAction extends Action
{
    /** @return array{therapies: Collection<int, Therapy>, groups: Collection<int, GroupTherapy>} */
    public function execute(User $ward): array
    {
        return [
            // SCRUM-33: a ward's quick help chat isn't paused, announced or shown to guardians.
            'therapies' => Therapy::query()
                ->withoutHelpChats()
                ->whereUser($ward)
                ->where('status', '!=', TherapyStatusEnum::ended->value)
                ->with(['counsellor.user', 'addedby'])
                ->get(),
            'groups' => GroupTherapy::query()
                ->whereUser($ward)
                ->where('status', '!=', TherapyStatusEnum::ended->value)
                ->get(),
        ];
    }

    public function hasAny(array $open): bool
    {
        return $open['therapies']->isNotEmpty() || $open['groups']->isNotEmpty();
    }
}
