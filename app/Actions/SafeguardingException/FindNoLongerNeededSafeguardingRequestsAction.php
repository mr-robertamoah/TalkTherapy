<?php

namespace App\Actions\SafeguardingException;

use App\Actions\Action;
use App\Models\Request;
use App\Models\User;
use Illuminate\Support\Collection;

// SCRUM-387 (review): counsellors' requests for a safeguarding exception still waiting although the
// minor no longer needs one (GetSafeguardingRequestNoLongerNeededReasonAction says why) -- the
// safeguarding page's "No longer needed" list and the admin overview's count of it, from one place.
// Pending is Request::scopePendingSafeguardingException(), the one definition of it.
class FindNoLongerNeededSafeguardingRequestsAction extends Action
{
    /**
     * By minor (their id, a request with none first), newest request first within each.
     *
     * @param  ?Collection<int, Request>  $pending  the pending requests, when the caller has them already
     * @return Collection<int, array{request: Request, ward: ?User, why: string}>
     */
    public function execute(?Collection $pending = null): Collection
    {
        $pending ??= Request::query()->pendingSafeguardingException()->get();

        $wards = User::withTrashed()
            ->whereIn('id', $pending->map(fn (Request $request) => $request->safeguardingWardId())->filter()->unique()->values())
            ->get()
            ->keyBy('id');
        $reason = GetSafeguardingRequestNoLongerNeededReasonAction::new();

        return $pending
            ->groupBy(fn (Request $request) => (int) $request->safeguardingWardId())
            ->sortKeys()
            ->flatMap(function (Collection $requests, int $wardId) use ($wards, $reason) {
                $ward = $wards->get($wardId);
                $why = $reason->execute($ward);

                return is_null($why)
                    ? []
                    : $requests->sortByDesc('id')->map(fn (Request $request) => ['request' => $request, 'ward' => $ward, 'why' => $why])->values();
            })
            ->values();
    }
}
