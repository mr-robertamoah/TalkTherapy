<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\Enums\RequestTypeEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Request;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphTo;

// SCRUM-384: what RequestResource reads for every row of a requests list, loaded once for the page
// instead of once per row -- each `from`, `to` (a counsellor with their user) and `for`, and for a safeguarding exception request the minor it is
// about (data.wardId, deleted accounts included, as `safeguardingWard`) plus what displayNameOf() needs to name
// them: the therapy's creator, and a group's members (GroupTherapy::membershipOf() reads them when
// loaded). Same idea as MessageService/SessionService setting `for` on each row.
class PreloadRequestListAction extends Action
{
    public function execute(Paginator|Collection $requests): Paginator|Collection
    {
        $rows = new Collection($requests instanceof Paginator ? $requests->items() : $requests->all());

        if ($rows->isEmpty()) {
            return $requests;
        }

        $withCounsellorUser = fn (MorphTo $morph) => $morph->morphWith([Counsellor::class => ['user']]);
        $rows->load(['from' => $withCounsellorUser, 'to' => $withCounsellorUser, 'for']);

        $safeguarding = $rows->filter(fn (Request $request) => $request->type === RequestTypeEnum::safeguardingException->value)->values();

        if ($safeguarding->isEmpty()) {
            return $requests;
        }

        $safeguarding->loadMorph('for', [
            Therapy::class => ['addedby'],
            GroupTherapy::class => ['addedby', 'users'],
        ]);

        $wards = User::withTrashed()
            ->with('counsellor')
            ->whereIn('id', $safeguarding->map(fn (Request $request) => $request->safeguardingWardId())->filter()->unique()->values())
            ->get()
            ->keyBy('id');

        $safeguarding->each(fn (Request $request) => $request->setRelation('safeguardingWard', $wards->get($request->safeguardingWardId())));

        return $requests;
    }
}
