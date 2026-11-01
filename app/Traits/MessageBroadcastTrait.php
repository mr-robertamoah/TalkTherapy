<?php

namespace App\Traits;

use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Models\Session;

trait MessageBroadcastTrait
{
    private function getMessageBroadcastName(Message $message)
    {
        if ($message->for_type == Session::class) {
            return "sessions.{$message->for_id}";
        }

        return "discussions.{$message->for_id}";
    }

    // SCRUM-366/SCRUM-271: a new or edited message is announced, never delivered -- the payload is
    // just its id and the (already masked, SCRUM-195) sender id, so each page can skip its own
    // messages. Every page then fetches the message from api.messages.show
    // (resolveBroadcastMessage.js), which applies all the per-viewer rules of a page load: a
    // suspended account (403), a participant who hasn't paid a strict-gated therapy, or anyone who
    // may no longer see it gets nothing. A websocket subscription that outlives someone's access
    // therefore learns only that a message arrived, never what it says.
    public function getMessageBroadcastData(Message $message): array
    {
        return [
            'id' => $message->id,
            'fromUserId' => MessageResource::present($message, null)['fromUserId'] ?? null,
            // Always true: it tells the page "fetch me". Never send content alongside it.
            'withheld' => true,
        ];
    }

    // A message deleted for everyone: its "deleted" shape carries no content (MessageResource), so
    // it is sent as it is -- there is nothing to fetch.
    public function getDeletedMessageBroadcastData(Message $message): array
    {
        // Never content: a message that isn't actually deleted is only announced.
        if (! $message->deleted_at) {
            return $this->getMessageBroadcastData($message);
        }

        return MessageResource::present($message, null);
    }
}
