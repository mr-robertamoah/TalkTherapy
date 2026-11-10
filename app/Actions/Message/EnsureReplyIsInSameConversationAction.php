<?php

namespace App\Actions\Message;

use App\Actions\Action;
use App\DTOs\CreateMessageDTO;
use App\Exceptions\MessageException;

// SCRUM-195 (security review): a reply may only point at a message in the SAME session or
// discussion. replyId used to accept any message id at all, and the reply's own response and
// broadcast then carried that message's content -- a way to read any message in the system,
// confidential ones included, by enumerating ids.
class EnsureReplyIsInSameConversationAction extends Action
{
    public function execute(CreateMessageDTO $createMessageDTO): void
    {
        $reply = $createMessageDTO->reply;

        if (! $reply) {
            return;
        }

        $for = $createMessageDTO->for;

        if ($for && $reply->for_type === $for::class && (int) $reply->for_id === $for->id) {
            return;
        }

        throw new MessageException('You can only reply to a message in the same conversation.', 422);
    }
}
