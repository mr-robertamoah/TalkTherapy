<?php

namespace App\Actions\Message;

use App\Actions\Action;
use App\DTOs\CreateMessageDTO;
use App\Exceptions\MessageException;

// SCRUM-361: a confidential message can be read only by its sender and its recipient
// (Message::isParty). Without a single recipient (a discussion, or a group with several
// counsellors) nobody but the sender could ever read it, so it is refused -- the chat only offers
// the switch when there is one recipient.
class EnsureConfidentialMessageHasRecipientAction extends Action
{
    public const NO_RECIPIENT = 'A confidential message needs one person to send it to.';

    public function execute(CreateMessageDTO $createMessageDTO): void
    {
        // On an edit, only when it is being made confidential now -- an older message that is
        // already confidential stays editable.
        $editing = (bool) $createMessageDTO->message;
        $confidential = $editing ? $createMessageDTO->confidential === true && ! $createMessageDTO->message->confidential : (bool) $createMessageDTO->confidential;
        $to = $editing ? $createMessageDTO->message->to_id : $createMessageDTO->to;

        if ($confidential && ! $to) {
            throw new MessageException(self::NO_RECIPIENT, 422);
        }
    }
}
