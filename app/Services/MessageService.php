<?php

namespace App\Services;

use App\Actions\Message\CreateMessageAction;
use App\Actions\Message\DeleteMessageAction;
use App\Actions\Message\DeleteMessageForMeAction;
use App\Actions\Message\EnsureCanDeleteMessageForSelfAction;
use App\Actions\Message\EnsureCanSendMessageToForAction;
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
use App\Models\Message;
use App\Models\Session;
use Illuminate\Support\Facades\DB;

class MessageService extends Service
{
    public function getSessionMessages(GetSessionMessagesDTO $getSessionMessagesDTO)
    {
        if (
            $getSessionMessagesDTO->user?->isNotAdmin() &&
            !$getSessionMessagesDTO->session?->for?->public &&
            $getSessionMessagesDTO->session?->for?->isNotParticipant($getSessionMessagesDTO->user)
        ) return [];
        
        $query = $getSessionMessagesDTO->session->messages()
            ->withTrashed()
            ->with(['therapyTopic'])
            ->when($getSessionMessagesDTO->like, function($query) use ($getSessionMessagesDTO) {
                $query->whereLike($getSessionMessagesDTO->like);
            })
            ->when($getSessionMessagesDTO->topicId, function($query) use ($getSessionMessagesDTO) {
                $query->whereTherapyTopicId($getSessionMessagesDTO->topicId);
            })
            ->when($getSessionMessagesDTO->replyId, function($query) use ($getSessionMessagesDTO) {
                $query->whereReplyId($getSessionMessagesDTO->replyId);
            })
            ->when($getSessionMessagesDTO->groupBy, function ($query) {
                $query
                    ->leftJoin('therapy_topics', 'messages.therapy_topic_id', '=', 'therapy_topic.id')
                    ->select('messages.*', DB::raw('COALESCE(therapy_topic.name, "No Topic") as topic_name'))
                    ->groupBy('topic_name');
            });
        
        return MessageResource::collection($query->latest()->paginate(
            PaginationEnum::preferencesPagination->value
        ));
    }
    
    public function getDiscussionMessages(GetDiscussionMessagesDTO $getDiscussionMessagesDTO)
    {
        if (
            $getDiscussionMessagesDTO->user?->isNotAdmin() &&
            $getDiscussionMessagesDTO->discussion?->isNotParticipant($getDiscussionMessagesDTO->user?->counsellor)
        ) return [];
        
        $query = $getDiscussionMessagesDTO->discussion->messages()
            ->withTrashed()
            ->when($getDiscussionMessagesDTO->like, function($query) use ($getDiscussionMessagesDTO) {
                $query->whereLike($getDiscussionMessagesDTO->like);
            })
            ->when($getDiscussionMessagesDTO->replyId, function($query) use ($getDiscussionMessagesDTO) {
                $query->whereReplyId($getDiscussionMessagesDTO->replyId);
            });
        
        return MessageResource::collection($query->latest()->paginate(
            PaginationEnum::preferencesPagination->value
        ));
    }
    
    public function getTherapyTopicMessages(GetTherapyTopicMessagesDTO $getTherapyTopicMessagesDTO)
    {
        if (!$getTherapyTopicMessagesDTO->topic) return [];
        
        $therapy = $getTherapyTopicMessagesDTO->topic->sessions()
            ->where('session_id', $getTherapyTopicMessagesDTO->sessionId)->first()
            ?->for;

        if (
            $getTherapyTopicMessagesDTO->user?->isNotAdmin() &&
            !$therapy?->public &&
            $therapy?->isNotParticipant($getTherapyTopicMessagesDTO->user)
        ) return [];
        
        $query = $getTherapyTopicMessagesDTO->topic->messages()
            ->withTrashed()
            ->with(['for'])
            ->when($getTherapyTopicMessagesDTO->like, function($query) use ($getTherapyTopicMessagesDTO) {
                $query->whereLike($getTherapyTopicMessagesDTO->like);
            })
            ->when($getTherapyTopicMessagesDTO->sessionId, function($query) use ($getTherapyTopicMessagesDTO) {
                $query->whereSessionId($getTherapyTopicMessagesDTO->sessionId);
            })
            ->when($getTherapyTopicMessagesDTO->replyId, function($query) use ($getTherapyTopicMessagesDTO) {
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
    
    public function getMessageReplies(?Message $message)
    {
        if (!$message) return [];
        
        $query = $message->replies()->withTrashed();
        
        return MessageResource::collection($query->latest()->paginate(
            PaginationEnum::preferencesPagination->value
        ));
    }

    public function createMessage(CreateMessageDTO $createMessageDTO): Message
    {
        EnsureIsFromUserAction::new()->execute($createMessageDTO);

        EnsureCanSendMessageToForAction::new()->execute($createMessageDTO);

        EnsureMessageDataIsValidAction::new()->execute($createMessageDTO);
        
        $message = CreateMessageAction::new()->execute($createMessageDTO);

        broadcast(new MessageSentEvent($message))->toOthers();

        return $message;
    }

    public function updateMessage(CreateMessageDTO $createMessageDTO): Message
    {
        EnsureMessageExistsAction::new()->execute($createMessageDTO);

        EnsureCanUpdateMessageAction::new()->execute($createMessageDTO);

        EnsureMessageDataIsValidAction::new()->execute($createMessageDTO);
        
        $message = UpdateMessageAction::new()->execute($createMessageDTO);

        broadcast(new MessageUpdatedEvent($message))->toOthers();

        return $message;
    }

    public function deleteMessage(CreateMessageDTO $createMessageDTO): Message
    {
        EnsureMessageExistsAction::new()->execute($createMessageDTO);

        EnsureCanUpdateMessageAction::new()->execute($createMessageDTO);
        
        $message = DeleteMessageAction::new()->execute($createMessageDTO);

        broadcast(new MessageDeletedEvent($message))->toOthers();

        return $message;
    }

    public function deleteMessageForMe(CreateMessageDTO $createMessageDTO): Message
    {
        EnsureMessageExistsAction::new()->execute($createMessageDTO);

        EnsureCanDeleteMessageForSelfAction::new()->execute($createMessageDTO);
        
        $message = DeleteMessageForMeAction::new()->execute($createMessageDTO);

        return $message;
    }
}