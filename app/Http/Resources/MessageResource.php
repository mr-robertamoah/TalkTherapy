<?php

namespace App\Http\Resources;

use App\Models\Counsellor;
use App\Models\Session;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $fromCounsellor = $this->from_type == Counsellor::class;

        $fromId = $fromCounsellor ? $this->from?->user->id : $this->from_id;

        if ($this->deleted_at)
            return [
                'id' => $this->id,
                'status' => 'deleted for everyone',
                'fromUserId' => $fromId,
                'type' => $this->type,
                'updatedAt' => $this->updated_at,
            ];

        $user = $request->user();

        if ($user && str_contains($this->deleted_for ?: '', $user->id))
            return [
                'id' => $this->id,
                'fromUserId' => $fromId,
                'status' => 'deleted for me',
                'type' => $this->type,
                'updatedAt' => $this->updated_at,
            ];

        $counsellor = $fromCounsellor ? $this->from : $this->to?->counsellor;
        $counsellorAvatar = $counsellor->avatar?->url;

        $toId = !$fromCounsellor ? $this->to?->user?->id : $this->to_id;

        if ($this->confidential && $this->isNotParty($user))
            return [
                'id' => $this->id,
                'fromUserId' => $fromId,
                'fromCounsellor' => $fromCounsellor,
                'toUserId' => $toId,
                'topicId' => $this->therapy_topic_id,
                'confidential' => $this->confidential,
                'status' => $this->status,
                'type' => $this->type,
                'updatedAt' => $this->updated_at,
            ];

        $forType = str_replace('App\Models\\', '', $this->for_type);
        $data = [
            'id' => $this->id,
            'fromUserId' => $fromId,
            'toUserId' => $toId,
            'fromCounsellor' => $fromCounsellor,
            'replying' => $this->when($this->replying, new MessageMiniResource($this->replying)),
            'counsellorAvatar' => $counsellorAvatar,
            'content' => $this->content,
            'confidential' => $this->confidential,
            'type' => $this->type,
            'topicId' => $this->therapy_topic_id,
            'forType' => $forType,
            'status' => $this->status,
            'files' => FileResource::collection($this->files),
            'updatedAt' => $this->updated_at,
            'createdAt' => $this->created_at,
        ];

        if ($forType == 'Discussion')  
            return array_merge($data, [
                'counsellorName' => $counsellor->getName(),
            ]);
            
        return $data;
    }
}
