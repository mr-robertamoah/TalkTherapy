<?php

namespace App\Actions\Help;

use App\Actions\Action;
use App\Enums\HelpRequestStatusEnum;
use App\Events\HelpRequestUpdatedEvent;
use App\Exceptions\HelpException;
use App\Models\HelpRequest;
use App\Models\User;
use App\Support\HelpBroadcast;

// SCRUM-33: either side ends a connected help chat. Afterwards it stays readable (read-only) to both.
class EndHelpChatAction extends Action
{
    public const NOT_CONNECTED = 'This chat has already ended.';

    public function execute(User $user, ?HelpRequest $request): HelpRequest
    {
        if (! $request || ! $request->isParticipant($user)) {
            throw new HelpException(self::NOT_CONNECTED, 404);
        }

        if (! CloseHelpRequestAction::new()->execute($request, HelpRequestStatusEnum::connected, HelpRequestStatusEnum::ended, $request->isFor($user) ? 'person' : 'counsellor')) {
            throw new HelpException(self::NOT_CONNECTED, 409);
        }

        HelpBroadcast::send(new HelpRequestUpdatedEvent($request->id, HelpRequestStatusEnum::ended->value));

        return $request->refresh();
    }
}
