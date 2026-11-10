<?php

namespace App\Actions\Help;

use App\Actions\Action;
use App\Models\Counsellor;
use App\Models\HelpRequest;
use App\Models\User;

// SCRUM-33 (TT-4.4b): the counsellors an administrator could ring directly for a waiting request --
// verified, not suspended, not the person or their guardian, not in a help chat -- on call first,
// searched by name.
class GetCounsellorsForHelpRequestAction extends Action
{
    public function execute(?User $admin, ?HelpRequest $request, ?string $search): array
    {
        $request = EnsureCanHandleHelpRequestAction::new()->execute($admin, $request);

        // Never the administrator's own counsellor profile (security review): they'd see who asked and answer.
        return Counsellor::query()
            ->ableToTakeUrgentHelp()->mayHelpWith($request)
            ->where('user_id', '!=', $admin->id)
            ->when($search, fn ($query, $term) => $query->whereName($term))
            ->orderByRaw('case when urgent_help_available_until > ? then 0 else 1 end', [now()])
            ->with('user')->orderBy('name')
            ->limit(20)
            ->get()
            ->map(fn (Counsellor $counsellor) => [
                'id' => $counsellor->id,
                'name' => $counsellor->getName(),
                'onCall' => $counsellor->isAvailableForUrgentHelp(),
            ])->all();
    }
}
