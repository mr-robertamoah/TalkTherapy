<?php

namespace App\Actions\Help;

use App\Actions\Action;
use App\Enums\HelpRequestStatusEnum;
use App\Models\Counsellor;
use App\Models\Guardianship;
use App\Models\HelpRequest;
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
                ->orWhere('updated_at', '>=', now()->subDay()))
            ->when($search, fn ($query, $term) => $query->where(fn ($query) => $query
                ->whereHas('counsellor', fn ($query) => $query->whereName($term))))
            ->with(['user', 'counsellor.user', 'pulledCounsellor.user'])
            ->orderByRaw('case status when ? then 0 when ? then 1 else 2 end', [HelpRequestStatusEnum::waiting->value, HelpRequestStatusEnum::connected->value])
            ->latest('id')
            ->limit(100);

        $tooMany = $search ? AdminSearch::withoutInvolved($query, $admin, fn (HelpRequest $row, User $viewer) => $row->involvesAdmin($viewer, $wardIds)) : false;

        // A request the viewer is involved in isn't shown at all (security review): even "you're involved"
        // would tell a guardian their ward asked.
        $rows = $query->get()
            ->reject(fn (HelpRequest $request) => $request->involvesAdmin($admin, $wardIds))
            ->map(fn (HelpRequest $request) => $this->row($request));

        return [
            'requests' => $rows->values()->all(),
            'onCall' => Counsellor::query()->availableForUrgentHelp()->count(),
            'searchTooMany' => $tooMany,
        ];
    }

    private function row(HelpRequest $request): array
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
        ];
    }
}
