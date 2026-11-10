<?php

namespace App\Actions\Message;

use App\Actions\Action;
use App\DTOs\CreateMessageDTO;
use App\Exceptions\MessageException;
use App\Models\Counsellor;
use App\Models\Session;
use App\Models\Therapy;

// SCRUM-348: in an individual therapy session the recipient is always the other party, so the
// server decides it instead of trusting a client-sent toId. That lets TherapyResource stop sending
// an anonymous client's real id to their counsellor (it was only there to address messages).
// Runs after EnsureIsFromUserAction, so `from` is the authenticated user's own account. Group and
// discussion messages keep their optional, participant-checked `to`.
class ResolveTherapyMessageRecipientAction extends Action
{
    public function execute(CreateMessageDTO $createMessageDTO): void
    {
        $session = $createMessageDTO->for;

        if (! $session instanceof Session || ! $session->for instanceof Therapy) {
            return;
        }

        $therapy = $session->for;
        $from = $createMessageDTO->from;

        if ($from instanceof Counsellor && $therapy->isCounsellor($from)) {
            $createMessageDTO->to = $therapy->addedby;

            return;
        }

        if ($from && $therapy->addedby?->is($from)) {
            $createMessageDTO->to = $therapy->counsellor;

            return;
        }

        // Security review: fail closed. Anyone else here is a party using their other account (the
        // counsellor as their User, the client as a Counsellor profile); letting their own `to`
        // through would let a counsellor confirm guessed client ids by success vs "not participating".
        throw new MessageException('You are not allowed to create a message for this session.', 422);
    }
}
