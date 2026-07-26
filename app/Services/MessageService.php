<?php

namespace App\Services;

use App\Actions\Message\CreateMessageAction;
use App\Actions\Message\DeleteMessageAction;
use App\Actions\Message\DeleteMessageForMeAction;
use App\Actions\Message\EnsureCanDeleteMessageForSelfAction;
use App\Actions\Message\EnsureCanSendMessageToForAction;
use App\Actions\Message\EnsureCanSendMessageToRecepientAction;
use App\Actions\Message\EnsureCanUpdateMessageAction;
use App\Actions\Message\EnsureIsFromUserAction;
use App\Actions\Message\EnsureMessageDataIsValidAction;
use App\Actions\Message\EnsureMessageExistsAction;
use App\Actions\Message\UpdateMessageAction;
use App\DTOs\CreateMessageDTO;
use App\DTOs\GetDiscussionMessagesDTO;
use App\DTOs\GetSessionMessagesDTO;
use App\DTOs\GetTherapyTopicMessagesDTO;
use App\Enums\PaginationEnum;
use App\Events\MessageDeletedEvent;
use App\Events\MessageSentEvent;
use App\Events\MessageUpdatedEvent;
use App\Http\Resources\MessageResource;
use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\Message;
use App\Models\Session;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Class MessageService
 *
 * This service handles messaging between users or counsellors for sessions and discussions.
 */
class MessageService extends Service
{
    /**
     * Gets messages for a particular session (public or private)
     *
     * @return array|AnonymousResourceCollection
     */
    public function getSessionMessages(GetSessionMessagesDTO $getSessionMessagesDTO)
    {
        $user = $getSessionMessagesDTO->user;

        // SCRUM-74: a null $user must deny by default. The previous `$user?->isNotAdmin() && ...`
        // form short-circuited to null (falsy) whenever $user was null, so this guard clause was
        // silently skipped entirely for an unauthenticated caller -- it never actually denied
        // anyone, it just never ran.
        if (! $user) {
            return [];
        }

        if ($user->isNotAdmin()) {
            $therapy = $getSessionMessagesDTO->session?->for;

            // A null $therapy (its parent Therapy/GroupTherapy has been soft-deleted --
            // Message::for()/Session::for() aren't withTrashed()) must deny by default for a
            // non-admin, same reasoning as the null-$user fix above: the previous `?->` chain
            // silently evaluated to falsy and skipped the restriction instead of denying.
            if (! $therapy || (! $therapy->public && $therapy->isNotParticipant($user))) {
                return [];
            }
        }

        $query = $getSessionMessagesDTO->session->messages()
            ->withTrashed()
            ->with(['therapyTopic', 'from'])
            ->when($getSessionMessagesDTO->like, function ($query) use ($getSessionMessagesDTO) {
                $query->whereLike($getSessionMessagesDTO->like);
            })
            ->when($getSessionMessagesDTO->topicId, function ($query) use ($getSessionMessagesDTO) {
                $query->whereTherapyTopicId($getSessionMessagesDTO->topicId);
            })
            ->when($getSessionMessagesDTO->replyId, function ($query) use ($getSessionMessagesDTO) {
                $query->whereReplyId($getSessionMessagesDTO->replyId);
            })
            ->when($getSessionMessagesDTO->groupBy, function ($query) {
                $query
                    ->leftJoin('therapy_topics', 'messages.therapy_topic_id', '=', 'therapy_topic.id')
                    ->select('messages.*', DB::raw('COALESCE(therapy_topic.name, "No Topic") as topic_name'))
                    ->groupBy('topic_name');
            });

        // Every message here is scoped through this single session (session->messages()), so
        // its `for` (and, for a GroupTherapy, that GroupTherapy's `users` anonymity pivot) is
        // identical for every row -- resolve it once and share the same instance across all of
        // them, rather than letting each Message independently re-resolve for/for.for/users.
        $session = $getSessionMessagesDTO->session;
        $session->loadMissing('for');
        if ($session->for instanceof GroupTherapy) {
            $session->for->loadMissing('users');
        }

        $messages = $query->latest()->paginate(
            PaginationEnum::preferencesPagination->value
        );
        $messages->getCollection()->each(fn (Message $message) => $message->setRelation('for', $session));

        return MessageResource::collection($messages);
    }

    /**
     * Gets messages for a particular discussion
     *
     * @return array|AnonymousResourceCollection
     */
    public function getDiscussionMessages(GetDiscussionMessagesDTO $getDiscussionMessagesDTO)
    {
        $user = $getDiscussionMessagesDTO->user;

        // SCRUM-74: same null-user-defaults-to-allow bug pattern as getSessionMessages(), fixed
        // for consistency/defense-in-depth even though this route already sits behind auth:sanctum.
        // Also guard a null discussion (bad/nonexistent discussionId) explicitly, rather than
        // falling through to a crash on ->messages() below.
        if (! $user || ! $getDiscussionMessagesDTO->discussion) {
            return [];
        }

        if (
            $user->isNotAdmin() &&
            $getDiscussionMessagesDTO->discussion->isNotParticipant($user->counsellor)
        ) {
            return [];
        }

        $query = $getDiscussionMessagesDTO->discussion->messages()
            ->withTrashed()
            ->when($getDiscussionMessagesDTO->like, function ($query) use ($getDiscussionMessagesDTO) {
                $query->whereLike($getDiscussionMessagesDTO->like);
            })
            ->when($getDiscussionMessagesDTO->replyId, function ($query) use ($getDiscussionMessagesDTO) {
                $query->whereReplyId($getDiscussionMessagesDTO->replyId);
            });

        return MessageResource::collection($query->latest()->paginate(
            PaginationEnum::preferencesPagination->value
        ));
    }

    /**
     * Gets messages for a particular therapy topic
     *
     * @return array|AnonymousResourceCollection
     */
    public function getTherapyTopicMessages(GetTherapyTopicMessagesDTO $getTherapyTopicMessagesDTO)
    {
        if (! $getTherapyTopicMessagesDTO->topic) {
            return [];
        }

        $therapy = $getTherapyTopicMessagesDTO->topic->sessions()
            ->where('session_id', $getTherapyTopicMessagesDTO->sessionId)->first()
            ?->for;

        $user = $getTherapyTopicMessagesDTO->user;

        // SCRUM-74: same null-user-defaults-to-allow bug pattern as getSessionMessages().
        if (! $user) {
            return [];
        }

        if ($user->isNotAdmin() && (! $therapy || (! $therapy->public && $therapy->isNotParticipant($user)))) {
            return [];
        }

        $query = $getTherapyTopicMessagesDTO->topic->messages()
            ->withTrashed()
            // A topic's messages can span several sessions, so (unlike getSessionMessages())
            // there's no single shared `for` instance to reuse here -- eager-load the nested
            // for.for (Session -> Therapy) in one batched query instead of letting each message
            // resolve it independently. TherapyTopic's topicable relation (TherapyTrait) is
            // technically polymorphic across Therapy/GroupTherapy, but no current write path
            // (TherapyTopicController::createTherapyTopic() resolves via Therapy::find()) ever
            // creates one for a GroupTherapy, so `for.for` is a Therapy in practice today --
            // if that ever changes, this eager-load also needs GroupTherapy's `users` pivot
            // (see Session::isAnonymousFor()) to avoid reintroducing this same N+1.
            ->with(['for.for', 'from'])
            ->when($getTherapyTopicMessagesDTO->like, function ($query) use ($getTherapyTopicMessagesDTO) {
                $query->whereLike($getTherapyTopicMessagesDTO->like);
            })
            ->when($getTherapyTopicMessagesDTO->sessionId, function ($query) use ($getTherapyTopicMessagesDTO) {
                $query->whereSessionId($getTherapyTopicMessagesDTO->sessionId);
            })
            ->when($getTherapyTopicMessagesDTO->replyId, function ($query) use ($getTherapyTopicMessagesDTO) {
                $query->whereReplyId($getTherapyTopicMessagesDTO->replyId);
            })
            ->when($getTherapyTopicMessagesDTO->groupBy, function ($query) {
                $query
                    ->where('for_type', Session::class)
                    ->leftJoin('sessions', 'messages.for_id', '=', 'session.id')
                    ->select('messages.*', DB::raw('COALESCE(session.name, "No Session") as session_name'))
                    ->groupBy('session_name');
            });

        return MessageResource::collection($query->latest()->paginate(
            PaginationEnum::preferencesPagination->value
        ));
    }

    public function getMessageReplies(?Message $message, ?User $user)
    {
        if (! $message) {
            return [];
        }

        // SCRUM-74: this previously had NO authorization check at all -- any caller (including,
        // before this route moved behind auth:sanctum, an unauthenticated one) could fetch
        // replies to any message by id. Mirror the same participant checks used by
        // getSessionMessages()/getDiscussionMessages(), based on whatever the parent message
        // belongs to.
        if (! $user) {
            return [];
        }

        if ($user->isNotAdmin()) {
            $for = $message->for;

            if ($for instanceof Session) {
                $therapy = $for->for;

                if (! $therapy || (! $therapy->public && $therapy->isNotParticipant($user))) {
                    return [];
                }
            } elseif ($for instanceof Discussion) {
                if ($for->isNotParticipant($user->counsellor)) {
                    return [];
                }
            } else {
                // $for is null (the message's own Session/Discussion has been soft-deleted --
                // Message::for() isn't withTrashed()) or an unexpected type -- deny by default
                // instead of silently falling through to the unguarded query below.
                return [];
            }
        }

        $query = $message->replies()->withTrashed();

        return MessageResource::collection($query->latest()->paginate(
            PaginationEnum::preferencesPagination->value
        ));
    }

    /**
     * Creates and broadcasts a message
     */
    public function createMessage(CreateMessageDTO $createMessageDTO): Message
    {
        EnsureIsFromUserAction::new()->execute($createMessageDTO);

        EnsureCanSendMessageToForAction::new()->execute($createMessageDTO);

        EnsureCanSendMessageToRecepientAction::new()->execute($createMessageDTO);

        EnsureMessageDataIsValidAction::new()->execute($createMessageDTO);

        $message = CreateMessageAction::new()->execute($createMessageDTO);

        broadcast(new MessageSentEvent($message))->toOthers();

        return $message;
    }

    /**
     * Updates and broadcasts a message
     */
    public function updateMessage(CreateMessageDTO $createMessageDTO): Message
    {
        EnsureMessageExistsAction::new()->execute($createMessageDTO);

        EnsureCanUpdateMessageAction::new()->execute($createMessageDTO);

        EnsureMessageDataIsValidAction::new()->execute($createMessageDTO, true);

        $message = UpdateMessageAction::new()->execute($createMessageDTO);

        broadcast(new MessageUpdatedEvent($message))->toOthers();

        return $message;
    }

    /**
     * Deletes and broadcasts the deleted message
     */
    public function deleteMessage(CreateMessageDTO $createMessageDTO): Message
    {
        EnsureMessageExistsAction::new()->execute($createMessageDTO);

        EnsureCanUpdateMessageAction::new()->execute($createMessageDTO);

        $message = DeleteMessageAction::new()->execute($createMessageDTO);

        broadcast(new MessageDeletedEvent($message))->toOthers();

        return $message;
    }

    /**
     * Deletes a message for a particular user/counsellor alone
     */
    public function deleteMessageForMe(CreateMessageDTO $createMessageDTO): Message
    {
        EnsureMessageExistsAction::new()->execute($createMessageDTO);

        EnsureCanDeleteMessageForSelfAction::new()->execute($createMessageDTO);

        $message = DeleteMessageForMeAction::new()->execute($createMessageDTO);

        return $message;
    }
}
