<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\Models\GroupTherapy;
use App\Models\Therapy;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

// SCRUM-420: who a therapy's payment is for -- its client, or a group's user creator and current members, never
// one of the group's own active counsellors (they run it, they don't pay into it). The creator comes first: the
// "first seat" a shared fixed rate's remainder goes to. $joinedBy leaves out members who joined after that moment
// (a held session's end), so a session is billed for who could have been in it.
class GetTherapyClientsAction extends Action
{
    /**
     * @return Collection<int, User>
     */
    public function execute(Therapy|GroupTherapy $therapy, ?CarbonInterface $joinedBy = null): Collection
    {
        if ($therapy instanceof Therapy) {
            return collect([$therapy->addedby])->filter(fn ($addedby) => $addedby instanceof User)->values();
        }

        $counsellorUserIds = $therapy->activeCounsellors()->pluck('user_id');

        // In joining order, so the same member is "first" (a shared remainder) every time it's asked.
        $members = $therapy->users()->orderByPivot('created_at')->orderBy('users.id');

        // On the relation itself: when() would hand the closure the query, where wherePivot() means something else.
        if ($joinedBy) {
            $members->wherePivot('created_at', '<=', $joinedBy);
        }

        return $members->get()
            ->when($therapy->addedby instanceof User, fn (Collection $users) => $users->prepend($therapy->addedby))
            ->unique('id')
            ->reject(fn (User $user) => $counsellorUserIds->contains($user->id))
            ->values();
    }
}
