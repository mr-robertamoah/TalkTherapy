<?php

namespace App\Actions\Help;

use App\Actions\Action;
use App\Actions\Admin\RecordAdminContentReadAction;
use App\Exceptions\HelpException;
use App\Models\AdminContentAccess;
use App\Models\HelpRequest;
use App\Models\User;

// SCRUM-33 (TT-4.4b, the user's decision): an administrator handling a help request may see who asked
// -- even when they asked anonymously -- and every time they do it's recorded in the read log.
class RevealHelpRequesterAction extends Action
{
    public function execute(?User $admin, ?HelpRequest $request): array
    {
        $request = EnsureCanHandleHelpRequestAction::new()->execute($admin, $request, waiting: false);

        // Only what the queue shows -- live, or closed in the last day (security review), not history.
        if (! $request->status->isLive() && $request->updated_at?->lt(now()->subDay())) {
            throw new HelpException(EnsureCanHandleHelpRequestAction::NOT_ALLOWED, 403);
        }

        // The read log's one write path (no therapy context: always recorded).
        RecordAdminContentReadAction::new()->execute($admin, AdminContentAccess::HELP_REQUEST_IDENTITY, $request);
        $person = $request->user;

        return [
            'name' => $person?->name,
            'username' => $person?->username,
            'email' => $person?->email,
        ];
    }
}
