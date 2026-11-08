<?php

namespace App\Actions\Help;

use App\Actions\Action;
use App\Enums\HelpRequestStatusEnum;
use App\Events\HelpRequestUpdatedEvent;
use App\Exceptions\HelpException;
use App\Models\HelpRequest;
use App\Models\User;
use App\Support\HelpBroadcast;

// SCRUM-33: the person stops waiting (only while nobody has answered -- a connected chat is ended
// instead). Any ring for it closes.
class CancelHelpRequestAction extends Action
{
    public const NOT_WAITING = 'This request is no longer waiting.';

    public function execute(User $user, ?HelpRequest $request): HelpRequest
    {
        if (! $request || ! $request->isFor($user)) {
            throw new HelpException(self::NOT_WAITING, 404);
        }

        $others = RingAvailableCounsellorsAction::ringingFor($request);

        if (! CloseHelpRequestAction::new()->execute($request, HelpRequestStatusEnum::waiting, HelpRequestStatusEnum::cancelled, 'person')) {
            throw new HelpException(self::NOT_WAITING, 409);
        }

        HelpBroadcast::send(new HelpRequestUpdatedEvent($request->id, HelpRequestStatusEnum::cancelled->value, $others));

        return $request->refresh();
    }
}
