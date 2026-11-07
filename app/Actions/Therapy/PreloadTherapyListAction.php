<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\Models\Therapy;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Collection;

// SCRUM-114: what TherapyMiniResource reads for every row of a therapy list, loaded once for the page
// instead of once per row (the therapy twin of PreloadGroupTherapyListAction): its creator, its
// counsellor with their user and avatar, and the held-session count (TherapyTrait prefers it).
class PreloadTherapyListAction extends Action
{
    public function execute(Paginator|Collection|array $therapies): Paginator|Collection|array
    {
        $rows = new Collection(match (true) {
            $therapies instanceof Paginator => $therapies->items(),
            $therapies instanceof Collection => $therapies->all(),
            default => $therapies,
        });

        $rows = $rows->filter(fn ($therapy) => $therapy instanceof Therapy)->values();

        if ($rows->isEmpty()) {
            return $therapies;
        }

        $rows->loadMissing(['addedby', 'counsellor.user', 'counsellor.avatarFile']);
        $rows->loadCount(['sessions as sessions_held_count' => fn ($query) => $query->whereHeld()]);

        return $therapies;
    }
}
