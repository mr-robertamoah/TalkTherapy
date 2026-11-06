<?php

namespace App\Actions\GroupTherapy;

use App\Actions\Action;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphTo;

// SCRUM-386: what GroupTherapyMiniResource reads for every row of a group list, loaded once for the
// page instead of once per row (same idea as PreloadRequestListAction):
// - `addedby` (a counsellor with their user and avatar, for CounsellorMiniResource);
// - `users` with the membership pivot -- isMaskedToOthers() reads the creator's own row through
//   membershipOf() (SCRUM-71's N+1 finding);
// - `activeCounsellorLinks` (the active counsellors' id and user_id) -- isCounsellor() reads them
//   when loaded, for the masking and pause checks. The full `counsellors` relation is left alone;
// - the counsellor count and the held-session count, which GroupTherapy::getCounsellorsCountAttribute()
//   and TherapyTrait::getSessionsHeldAttribute() prefer when present.
// Relations already loaded by the caller are kept (loadMissing).
class PreloadGroupTherapyListAction extends Action
{
    public function execute(Paginator|Collection|array $groups): Paginator|Collection|array
    {
        $rows = new Collection(match (true) {
            $groups instanceof Paginator => $groups->items(),
            $groups instanceof Collection => $groups->all(),
            default => $groups,
        });

        $rows = $rows->filter(fn ($group) => $group instanceof GroupTherapy)->unique(fn (GroupTherapy $group) => spl_object_id($group))->values();

        if ($rows->isEmpty()) {
            return $groups;
        }

        $rows->loadMissing([
            'addedby' => fn (MorphTo $morph) => $morph->morphWith([Counsellor::class => ['user', 'avatarFile']]),
            'users',
            'activeCounsellorLinks',
        ]);

        $rows->loadCount([
            'counsellors',
            'sessions as sessions_held_count' => fn ($query) => $query->whereHeld(),
        ]);

        return $groups;
    }
}
