<?php

namespace App\Actions\Help;

use App\Actions\Action;
use App\Enums\HelpRequestStatusEnum;
use App\Enums\SuspensionScopeEnum;
use App\Models\Counsellor;
use App\Models\Guardianship;
use App\Models\HelpRequest;
use App\Models\Suspension;
use App\Models\User;
use App\Support\AdminSearch;
use App\Support\Countries;

// SCRUM-33 (TT-4.4b): the administrators' urgent help queue -- every request waiting or in a chat, then
// the last day's closed ones. Each row: the short summary a counsellor sees (never who asked -- that's
// revealed one at a time and logged), plus a suspended account, how long it's waited, the alerts, who
// was rung directly, the counsellor in it. A row the viewer is involved in isn't shown.
// Searched by the counsellor's name (never the person's).
class GetHelpQueueAction extends Action
{
    public function execute(User $admin, ?string $search = null): array
    {
        $wardIds = Guardianship::query()->where('guardian_id', $admin->id)->pluck('ward_id')->map(fn ($id) => (int) $id)->all();

        $query = HelpRequest::query()
            ->where(fn ($query) => $query
                ->whereIn('status', [HelpRequestStatusEnum::waiting->value, HelpRequestStatusEnum::connected->value])
                // TT-4.4c (QA): by when it ended -- a later change (an invitation, the retention sweep) mustn't
                // bring an old request back into "the last day".
                ->orWhere('ended_at', '>=', now()->subDay()))
            ->when($search, fn ($query, $term) => $query->where(fn ($query) => $query
                ->whereHas('counsellor', fn ($query) => $query->whereName($term))))
            ->with(['user', 'counsellor.user', 'pulledCounsellor.user'])
            ->orderByRaw('case status when ? then 0 when ? then 1 else 2 end', [HelpRequestStatusEnum::waiting->value, HelpRequestStatusEnum::connected->value])
            ->latest('id')
            ->limit(100);

        $tooMany = $search ? AdminSearch::withoutInvolved($query, $admin, fn (HelpRequest $row, User $viewer) => $row->involvesAdmin($viewer, $wardIds)) : false;

        // A request the viewer is involved in isn't shown at all (security review): even "you're involved"
        // would tell a guardian their ward asked.
        $requests = $query->get()->reject(fn (HelpRequest $request) => $request->involvesAdmin($admin, $wardIds));

        // TT-4.4c: which counsellors of ended chats are suspended -- one query for "Invite", not one a row.
        $endedCounsellorUserIds = $requests->where('status', HelpRequestStatusEnum::ended)->pluck('counsellor.user_id')->filter()->unique()->values();
        $suspendedCounsellorUserIds = $endedCounsellorUserIds->isEmpty() ? [] : Suspension::query()->active()
            ->covering(SuspensionScopeEnum::counsellor)->whereIn('user_id', $endedCounsellorUserIds)->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        $rows = $requests->map(fn (HelpRequest $request) => $this->row($request, $suspendedCounsellorUserIds));

        return [
            'requests' => $rows->values()->all(),
            'onCall' => Counsellor::query()->availableForUrgentHelp()->count(),
            'searchTooMany' => $tooMany,
        ];
    }

    private function row(HelpRequest $request, array $suspendedCounsellorUserIds): array
    {
        $person = $request->user;

        return [
            'id' => $request->id,
            'status' => $request->status->value,
            'createdAt' => $request->created_at?->toIso8601String(),
            'name' => $request->anonymous ? 'Anonymous' : ($person?->firstName ?: 'Someone'),
            'anonymous' => $request->anonymous,
            'isMinor' => $request->was_minor,
            'suspendedAccount' => $request->was_suspended,
            'country' => Countries::nameOf($request->country_code),
            'note' => $request->note,
            'acceptedAt' => $request->accepted_at?->toIso8601String(),
            'endedAt' => $request->ended_at?->toIso8601String(),
            'endedBy' => $request->ended_by,
            'escalatedAt' => $request->escalated_at?->toIso8601String(),
            'escalatedAgainAt' => $request->escalated_again_at?->toIso8601String(),
            'counsellor' => $request->counsellor?->getName(),
            'pulledCounsellor' => $request->pulledCounsellor?->getName(),
            'closedNote' => $request->closed_note,
            // TT-4.4c: invited to a regular therapy with the counsellor -- or, for an ended chat, could be.
            'invitedAt' => $request->invited_at?->toIso8601String(),
            'canInvite' => $request->canInviteToTherapy($suspendedCounsellorUserIds),
        ];
    }
}
