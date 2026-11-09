<?php

namespace App\Actions\Help;

use App\Actions\Action;
use App\Events\HelpRequestUpdatedEvent;
use App\Exceptions\HelpException;
use App\Models\HelpRequest;
use App\Models\User;
use App\Notifications\HelpTherapyInviteNotification;
use App\Support\HelpBroadcast;

// SCRUM-33 (TT-4.4c, the user's decision): after a help chat has ended, the counsellor in it -- or an
// administrator not involved in it -- can invite the person to continue in a regular therapy with that
// counsellor. It's an invitation only: the person starts the therapy themselves, through the usual
// form and request (so payment, a minor's guardian, a suspension and the counsellor's limits all apply
// as for any other therapy), and the help chat is never copied into it. Once a request.
class InviteHelpRequesterToTherapyAction extends Action
{
    public const NOT_POSSIBLE = 'This person can\'t be invited from this chat.';

    public const ALREADY_INVITED = 'They have already been invited.';

    // $asAdmin: from the administrators' queue (the admin routes); otherwise only the counsellor who was
    // in the chat, with the same answer as for a chat that doesn't exist (review).
    public function execute(?User $inviter, ?HelpRequest $request, bool $asAdmin = false): HelpRequest
    {
        if ($asAdmin) {
            // The same rules as for the queue's other actions (never one involved).
            $request = EnsureCanHandleHelpRequestAction::new()->execute($inviter, $request, waiting: false);
        } elseif (! $inviter || ! $request?->isAnsweredBy($inviter)) {
            throw new HelpException(GetHelpChatAction::NOT_FOUND, 404);
        }

        if (! $request->canInviteToTherapy()) {
            // Already invited is told apart; anything else is "not possible".
            throw $request->invited_at ? new HelpException(self::ALREADY_INVITED, 409) : new HelpException(self::NOT_POSSIBLE, 422);
        }

        $invited = HelpRequest::query()->whereKey($request->id)->whereNull('invited_at')
            ->update(['invited_at' => now(), 'invited_by_id' => $inviter->id, 'updated_at' => now()]);

        if ($invited === 0) {
            throw new HelpException(self::ALREADY_INVITED, 409);
        }

        $request->refresh();
        rescue(fn () => $request->user->notify(new HelpTherapyInviteNotification($request->id, $request->counsellor->getName(), ! $asAdmin, $request->counsellor->user)), report: true);
        HelpBroadcast::send(new HelpRequestUpdatedEvent($request->id, $request->status->value));
        HelpBroadcast::queueChanged($request->id);

        return $request;
    }
}
