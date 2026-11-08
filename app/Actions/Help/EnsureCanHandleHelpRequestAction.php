<?php

namespace App\Actions\Help;

use App\Actions\Action;
use App\Enums\HelpRequestStatusEnum;
use App\Exceptions\HelpException;
use App\Models\HelpRequest;
use App\Models\User;

// SCRUM-33 (TT-4.4b): an administrator acting on a help request -- an administrator, not involved in it
// (HelpRequest::involvesAdmin), and (for ringing and closing) it is still waiting. Missing, involved and
// no-longer-waiting get answers that never say who asked.
class EnsureCanHandleHelpRequestAction extends Action
{
    public const NOT_ALLOWED = 'You can\'t act on this request.';

    public const NOT_WAITING = 'This request is no longer waiting.';

    public function execute(?User $admin, ?HelpRequest $request, bool $waiting = true): HelpRequest
    {
        if (! $admin?->isAdmin() || ! $request || $request->involvesAdmin($admin)) {
            throw new HelpException(self::NOT_ALLOWED, 403);
        }

        if ($waiting && $request->status !== HelpRequestStatusEnum::waiting) {
            throw new HelpException(self::NOT_WAITING, 409);
        }

        return $request;
    }
}
