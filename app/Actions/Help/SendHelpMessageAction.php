<?php

namespace App\Actions\Help;

use App\Actions\Action;
use App\Enums\HelpRequestStatusEnum;
use App\Enums\MessageStatusEnum;
use App\Enums\MessageTypeEnum;
use App\Events\HelpMessageSentEvent;
use App\Exceptions\HelpException;
use App\Models\HelpRequest;
use App\Models\Message;
use App\Models\User;
use App\Support\HelpBroadcast;

// SCRUM-33: a message in a connected help chat -- plain text, from either side. Stored as an ordinary
// message on the chat's session (from the person, or from the counsellor's profile), with the
// person's anonymity kept on it. Help chats are deliberately outside the therapy chat's gates (the
// paused-minor pause, suspension): a crisis outranks them (the user's decision).
class SendHelpMessageAction extends Action
{
    public const NOT_CONNECTED = 'This chat isn\'t open.';

    public const EMPTY = 'Write a message first.';

    public const MAX = 2000;

    public function execute(User $user, ?HelpRequest $request, mixed $content): Message
    {
        if (! $request || ! $request->isParticipant($user)) {
            throw new HelpException(self::NOT_CONNECTED, 404);
        }

        // A counsellor who has since been suspended can't write (the chat is also ended for them --
        // EndCounsellorHelpChatsAction); the person, suspended or not, always can.
        if ($request->status !== HelpRequestStatusEnum::connected
            || (! $request->isFor($user) && ($user->counsellor?->isSuspended() ?? true))) {
            throw new HelpException(self::NOT_CONNECTED, 409);
        }

        $content = is_string($content) ? trim($content) : '';
        if ($content === '') {
            throw new HelpException(self::EMPTY, 422);
        }

        return $this->store($request, $user, mb_substr($content, 0, self::MAX));
    }

    public function store(HelpRequest $request, User $sender, string $content): Message
    {
        $fromPerson = $request->isFor($sender);
        $person = $request->user;
        $counsellor = $request->counsellor;

        $message = new Message([
            'content' => $content,
            'type' => MessageTypeEnum::normal->value,
            'status' => MessageStatusEnum::sent->value,
            'confidential' => false,
        ]);
        $message->from()->associate($fromPerson ? $person : $counsellor);
        $message->to()->associate($fromPerson ? $counsellor : $person);
        $message->for()->associate($request->session);
        $message->sender_anonymous = $fromPerson && $request->anonymous;
        $message->recipient_anonymous = ! $fromPerson && $request->anonymous;
        $message->save();

        HelpBroadcast::send(new HelpMessageSentEvent($request->id, $message->id));

        return $message;
    }
}
