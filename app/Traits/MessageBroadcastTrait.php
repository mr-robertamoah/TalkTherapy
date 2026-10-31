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

    // One payload for every subscriber of a shared channel, so it is MessageResource's own view for
    // NO viewer (SCRUM-195) -- by construction the most restricted one: a confidential message never
    // carries its content, files or replied-to content, and anonymous sender/recipient ids are
    // masked. A confidential message's parties fetch the full message from api.messages.show
    // (resolveBroadcastMessage.js). Note toOthers() only skips the sending socket: the sender's
    // other tabs get this restricted view of their own message too.
    public function getMessageBroadcastData(Message $message)
    {
        return MessageResource::present($message, null);
    }
}
