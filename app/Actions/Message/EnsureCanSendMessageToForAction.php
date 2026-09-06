<?php

namespace App\Actions\Message;

use App\Actions\Action;
use App\DTOs\CreateMessageDTO;
use App\Exceptions\MessageException;
use App\Models\Discussion;

// SCRUM-246: message _reading_ (getSessionMessages/getTherapyTopicMessages/getMessageReplies) has
// gone through EnsureUserCanAccessTherapyContentAction's shared strict-payment-gate check since
// SCRUM-220 -- message _creation_ never did, so a client blocked from reading a strict-gated (or,
// since SCRUM-238, org-billing-suspended) session's messages could still create new ones there.
// Discussion is deliberately NOT included here, mirroring that same action's own comment: a
// Discussion's participants are Counsellors, never the paying client themselves, so there is no
// client-payment-gating scenario on that branch to close.
class EnsureCanSendMessageToForAction extends Action
{
    public function execute(CreateMessageDTO $createMessageDTO)
    {
        if (! $createMessageDTO->for) {
            throw new MessageException('A message has to be created for a discussion or session.', 422);
        }

        if ($createMessageDTO->for->doesNotAcceptMessage()) {
            $type = $createMessageDTO->for::class == Discussion::class
                ? 'discussion'
                : 'session';
            throw new MessageException("The message cannot be sent because the {$type} may no more be in session.", 422);
        }

        if ($createMessageDTO->for::class == Discussion::class) {
            return $this->validateForDiscussion($createMessageDTO);
        }

        $this->validateForSession($createMessageDTO);
    }

    public function validateForSession(CreateMessageDTO $createMessageDTO)
    {
        $session = $createMessageDTO->for;

        if (! $session->isParticipant($createMessageDTO->user)) {
            throw new MessageException('You are not allowed to create a message for this session.', 422);
        }

        // SCRUM-246: same shared check the read-side methods already use -- a no-op for anyone
        // but the strict-gated therapy's own paying client (see that action's own comment on why
        // only `addedby` is affected), so a counsellor or already-participating co-client is never
        // blocked here.
        if (! EnsureUserCanAccessTherapyContentAction::new()->execute($session->for, $createMessageDTO->user, $session)) {
            throw new MessageException('You are not allowed to create a message for this session.', 422);
        }
    }

    public function validateForDiscussion(CreateMessageDTO $createMessageDTO)
    {
        $discussion = $createMessageDTO->for;

        if (
            $discussion->isParticipant($createMessageDTO->user->counsellor)
        ) {
            return;
        }

        throw new MessageException('You are not allowed to create a message for this discussion because you are not participating in it.', 422);
    }
}
