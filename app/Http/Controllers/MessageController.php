<?php

namespace App\Http\Controllers;

use App\Actions\GetModelWithModelNameAndIdAction;
use App\DTOs\CreateMessageDTO;
use App\DTOs\GetDiscussionMessagesDTO;
use App\DTOs\GetSessionMessagesDTO;
use App\DTOs\GetTherapyTopicMessagesDTO;
use App\Http\Requests\CreateMessageRequest;
use App\Http\Requests\UpdateMessageRequest;
use App\Http\Resources\MessageResource;
use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\Message;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\TherapyTopic;
use App\Services\MessageService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Throwable;

class MessageController extends Controller
{
    public function getMessageReplies(Request $request)
    {
        return MessageService::new()->getMessageReplies(
           Message::find($request->messageId)
        );
    }

    public function getSessionMessages(Request $request)
    {
        return MessageService::new()->getSessionMessages(
            GetSessionMessagesDTO::new()->fromArray([
                'user' => $request->user(),
                'session' => Session::find($request->sessionId),
                'like' => $request->like,
                'groupBy' => $request->groupBy,
                'topicId' => $request->topicId,
                'replyId' => $request->replyId,
            ])
        );
    }
    
    public function getTopicMessages(Request $request)
    {
        try {
            return MessageService::new()->getTherapyTopicMessages(
                GetTherapyTopicMessagesDTO::new()->fromArray([
                    'user' => $request->user(),
                    'sessionId' => $request->sessionId,
                    'like' => $request->like,
                    'groupBy' => $request->groupBy,
                    'topic' => TherapyTopic::find($request->topicId),
                ])
            );
        } catch (Throwable $th) {
            
            return $this->returnFailure($request, $th);
        }
    }
    
    public function getDiscussionMessages(Request $request)
    {
        return MessageService::new()->getDiscussionMessages(
            GetDiscussionMessagesDTO::new()->fromArray([
                'user' => $request->user(),
                'discussion' => Discussion::find($request->discussionId),
                'like' => $request->like,
                'replyId' => $request->replyId,
            ])
        );
    }
    
    public function createMessage(CreateMessageRequest $request)
    {
        try {
            $message = MessageService::new()->createMessage(
                CreateMessageDTO::new()->fromArray([
                    'user' => $request->user(),
                    'content' => $request->content,
                    'type' => $request->type,
                    'files' => $request->file('files'),
                    'reply' => Message::find($request->replyId),
                    'therapyTopic' => TherapyTopic::find($request->topicId),
                    'confidential' => (bool) $request->confidential,
                    'from' => GetModelWithModelNameAndIdAction::new()->execute($request->fromType, $request->fromId),
                    'to' => GetModelWithModelNameAndIdAction::new()->execute($request->toType, $request->toId),
                    'for' => GetModelWithModelNameAndIdAction::new()->execute($request->forType, $request->forId),
                ])
            );

            return $this->returnSuccess($request, $message);
        } catch (Throwable $th) {
            
            return $this->returnFailure($request, $th);
        }
    }
    
    public function updateMessage(UpdateMessageRequest $request)
    {
        $message = Message::find($request->messageId);
        
        try {
            $message = MessageService::new()->updateMessage(
                CreateMessageDTO::new()->fromArray([
                    'user' => $request->user(),
                    'content' => $request->content,
                    'type' => $request->type,
                    'deletedFiles' => $request->deletedFiles,
                    'files' => $request->file('files'),
                    'reply' => Message::find($request->replyId),
                    'message' => $message,
                    'therapyTopic' => TherapyTopic::find($request->topicId),
                    'confidential' => (bool) $request->confidential,
                    'for' => $message?->for,
                ])
            );

            return $this->returnSuccess($request, $message);
        } catch (Throwable $th) {
            
            return $this->returnFailure($request, $th);
        }
    }
    
    public function deleteMessage(Request $request)
    {
        try {
            $message = MessageService::new()->deleteMessage(
                CreateMessageDTO::new()->fromArray([
                    'user' => $request->user(),
                    'message' => Message::find($request->messageId),
                ])
            );

            return $this->returnSuccess($request, $message);
        } catch (Throwable $th) {
            
            return $this->returnFailure($request, $th);
        }
    }
    
    public function deleteMessageForMe(Request $request)
    {
        try {
            $message = MessageService::new()->deleteMessageForMe(
                CreateMessageDTO::new()->fromArray([
                    'user' => $request->user(),
                    'message' => Message::find($request->messageId),
                ])
            );

            return $this->returnSuccess($request, $message);
        } catch (Throwable $th) {
            
            return $this->returnFailure($request, $th);
        }
    }

    private function returnSuccess(Request $request, Message $message)
    {
        $message = new MessageResource($message);
        
        if ($request->acceptsJson()) return response()->json(['message' => $message]);
        
        return Redirect::back()->with(['message' => $message]);
    }

    private function returnFailure(Request $request, Throwable $th)
    {
        $message = $th->getCode() == 500 ? "Something unfortunate happened. Please try again shortly." : $th->getMessage();
        
        ds($th);

        if ($request->acceptsJson()) throw new Exception($message);
        return Redirect::back()->withErrors(['alert'=> $message]);
    }
}
