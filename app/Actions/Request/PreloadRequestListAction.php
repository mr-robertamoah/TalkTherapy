<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\Actions\GroupTherapy\PreloadGroupTherapyListAction;
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
// instead of once per row -- each `from`, `to` (a counsellor with their user and avatar) and `for`, and for a safeguarding exception request the minor it is
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

        // SCRUM-387: and their avatar (CounsellorMiniResource), also for a counsellor `for` (a
        // counsellor verification request), and a therapy `for`'s counsellor and held-session count
        // (TherapyMiniResource) -- for the admin queue, where most rows are about someone else.
        $withCounsellor = fn (MorphTo $morph) => $morph->morphWith([
            Counsellor::class => ['user', 'avatarFile'],
            Therapy::class => ['counsellor.user', 'counsellor.avatarFile'],
        ]);
        $rows->load(['from' => $withCounsellor, 'to' => $withCounsellor, 'for' => $withCounsellor]);
        $therapies = new Collection($rows->pluck('for')->filter(fn ($for) => $for instanceof Therapy)->unique(fn (Therapy $therapy) => spl_object_id($therapy))->values()->all());
        $therapies->loadCount(['sessions as sessions_held_count' => fn ($query) => $query->whereHeld()]);
        // SCRUM-386: what GroupTherapyMiniResource reads for a group `for`, once for the page.
        PreloadGroupTherapyListAction::new()->execute($rows->pluck('for')->filter(fn ($for) => $for instanceof GroupTherapy)->all());

        // SCRUM-387: who answered an admin queue request (RequestResource's `respondedBy`).
        $rows->filter(fn (Request $request) => ! is_null($request->responded_by_id))
            ->values()
            ->load('respondedBy');

        // SCRUM-386: an age verification's identity document (RequestResource's documentUrl).
        $rows->filter(fn (Request $request) => $request->type === RequestTypeEnum::ageVerification->value)
            ->values()
            ->load('identityDocument');

        $safeguarding = $rows->filter(fn (Request $request) => $request->type === RequestTypeEnum::safeguardingException->value)->values();

        if ($safeguarding->isEmpty()) {
            return $requests;
        }

        // A group's creator and members are already loaded (PreloadGroupTherapyListAction, above);
        // loading them again here would drop the creator's user and avatar.
        $safeguarding->loadMorph('for', [
            Therapy::class => ['addedby'],
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
