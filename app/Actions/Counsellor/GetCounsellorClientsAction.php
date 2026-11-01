<?php

namespace App\Actions\Counsellor;

use App\Actions\Action;
use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Enums\TherapyStatusEnum;
use App\Models\Counsellor;
use App\Models\User;
use Illuminate\Support\Collection;

// The clients of a counsellor: the User owners of their therapies, and the members (plus a User
// owner) of their group therapies -- never the other counsellors of a group. $current limits it to
// therapies that haven't ended and groups they are still an active counsellor of (SCRUM-359);
// without it, everyone they ever counselled (DeleteCounsellorAction).
class GetCounsellorClientsAction extends Action
{
    public function execute(Counsellor $counsellor, bool $current = false): Collection
    {
        $fromTherapies = $counsellor->therapies()
            ->when($current, fn ($q) => $q->where('status', '!=', TherapyStatusEnum::ended->value))
            ->get()
            ->map(fn ($therapy) => $therapy->addedby_type === User::class ? $therapy->addedby : null);

        // Deliberately NOT GroupTherapy::getUsers() -- that helper also returns every OTHER
        // counsellor attached to the group. Only actual members (the group_therapy_user pivot)
        // and a User-owner count as clients.
        // Not ->when(): on a relation it hands the callback the plain query builder, which has no
        // wherePivot().
        $groups = $counsellor->groupTherapies();
        if ($current) {
            $groups->wherePivot('state', CounsellorGroupTherapyStateEnum::active->value);
        }

        $fromGroupTherapies = $groups->get()
            ->flatMap(function ($groupTherapy) {
                $owner = $groupTherapy->addedby_type === User::class ? $groupTherapy->addedby : null;

                return $groupTherapy->users->merge([$owner]);
            });

        return $fromTherapies->merge($fromGroupTherapies)->filter()->unique('id')->values();
    }
}
