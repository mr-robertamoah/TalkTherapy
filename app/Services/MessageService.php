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
use App\DTOs\GetSessionMessageDTO;
use App\Enums\PaginationEnum;
use App\Events\MessageDeletedEvent;
use App\Events\MessageSentEvent;
use App\Events\MessageUpdatedEvent;
use App\Http\Resources\MessageResource;
use App\Http\Resources\SessionMessageResource;
use App\Models\Message;
use App\Models\Session;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class MessageService extends Service
{
    public function getSessionMessages(GetSessionMessageDTO $getSessionMessageDTO)
    {
        if (
            $getSessionMessageDTO->user?->isNotAdmin() &&
            !$getSessionMessageDTO->session?->for?->public &&
            $getSessionMessageDTO->session?->for?->isNotParticipant($getSessionMessageDTO->user)
        ) return [];
        
        $query = $getSessionMessageDTO->session->messages()
            ->with(['therapyTopic'])
            ->when($getSessionMessageDTO->like, function($query) use ($getSessionMessageDTO) {
                $query->whereLike($getSessionMessageDTO->like);
            })
            ->when($getSessionMessageDTO->topicId, function($query) use ($getSessionMessageDTO) {
                $query->whereTherapyTopicId($getSessionMessageDTO->topicId);
            })
            ->when($getSessionMessageDTO->replyId, function($query) use ($getSessionMessageDTO) {
                $query->whereReplyId($getSessionMessageDTO->replyId);
            })
            ->when($getSessionMessageDTO->groupBy, function ($query) {
                $query
                    ->leftJoin('therapy_topics', 'messages.therapy_topic_id', '=', 'therapy_topic.id')
                    ->select('messages.*', DB::raw('COALESCE(therapy_topic.name, "No Topic") as topic_name'))
                    ->groupBy('topic_name');
            });
        
        return MessageResource::collection($query->latest()->paginate(
            PaginationEnum::preferencesPagination->value
        ));
    }
    
    public function getMessageReplies(?Message $message)
    {
        if (!$message) return [];
        
        $query = $message->replies();
        
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