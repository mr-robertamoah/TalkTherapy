<?php

namespace App\Traits;

use App\Http\Resources\FileResource;
use App\Http\Resources\MessageMiniResource;
use App\Models\Counsellor;
use App\Models\Message;
use App\Models\Session;
use App\Models\Star;

trait MessageBroadcastTrait
{
    private function getMessageBroadcastName(Message $message)
    {
        if ($message->for_type == Session::class)
            return "sessions.{$message->for_id}";

        return "discussions.{$message->for_id}";
    }

    public function getMessageBroadcastData(Message $message)
    {
        $fromCounsellor = $message->from_type == Counsellor::class;

        $fromId = $fromCounsellor ? $message->from?->user->id : $message->from_id;

        if ($message->deleted_at)
            return [
                'id' => $message->id,
                'status' => 'deleted for everyone',
                'topicId' => $message->therapy_topic_id,
                'fromUserId' => $fromId,
                'type' => $message->type,
                'updatedAt' => $message->updated_at,
            ];

            
        $counsellor = $fromCounsellor ? $message->from->avatar?->url : $message->to?->counsellor?->avatar?->url;
            
        $toId = !$fromCounsellor ? $message->to?->user?->id : $message->to_id;
            
        return [
            'id' => $message->id,
            'fromUserId' => $fromId,
            'toUserId' => $toId,
            'fromCounsellor' => $fromCounsellor,
            'replying' => $message->replying ? new MessageMiniResource($message->replying) : null,
            'counsellorAvatar' => $counsellor,
            'content' => $message->content,
            'confidential' => $message->confidential,
            'type' => $message->type,
            'topicId' => $message->therapy_topic_id,
            'forType' => str_replace('App\Models\\', '', $message->for_type),
            'status' => $message->status,
            'files' => FileResource::collection($message->files),
            'updatedAt' => $message->updated_at,
            'createdAt' => $message->created_at,
        ];
    }
}