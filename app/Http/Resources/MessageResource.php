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

        $fromId = $fromCounsellor ? $this->from?->user?->id : $this->from_id;

        $user = $request->user();

        // Anonymity only ever applies to a client/User sender (never a counsellor -- confirmed
        // Discussion messages can *only* ever come from a counsellor, so `$this->for instanceof
        // Session` also naturally excludes Discussion without needing an extra type check), is
        // computed live from the current Therapy/GroupTherapy anonymity flag(s) rather than a
        // stored snapshot, and never applies when the viewer is the sender themselves (mirrors
        // TherapyResource's `$this->addedby?->is($user) || ! $this->anonymous` precedent).
        $fromUser = $fromCounsellor ? null : $this->from;
        $isAnonymousSender = $fromUser
            && $this->for instanceof Session
            && $this->for->isAnonymousFor($fromUser)
            && ! $fromUser->is($user);

        // fromUserId is never displayed as a name in the frontend -- it only drives equality
        // checks against the viewer's own id (own-message layout/edit/delete). Nulling it for a
        // masked message is therefore safe for every viewer except the sender themselves (who
        // must keep seeing their own real id so they can still edit/delete their own message).
        $maskedFromId = $isAnonymousSender ? null : $fromId;

        if ($this->deleted_at) {
            return [
                'id' => $this->id,
                'status' => 'deleted for everyone',
                'fromUserId' => $maskedFromId,
                'type' => $this->type,
                'updatedAt' => $this->updated_at,
            ];
        }

        if ($user && str_contains($this->deleted_for ?: '', $user->id)) {
            return [
                'id' => $this->id,
                'fromUserId' => $maskedFromId,
                'status' => 'deleted for me',
                'type' => $this->type,
                'updatedAt' => $this->updated_at,
            ];
        }

        $counsellor = $fromCounsellor ? $this->from : $this->to?->counsellor;
        $counsellorAvatar = $counsellor?->avatar?->url;

        $toId = ! $fromCounsellor ? $this->to?->user?->id : $this->to_id;

        if ($this->confidential && $this->isNotParty($user)) {
            return [
                'id' => $this->id,
                'fromUserId' => $maskedFromId,
                'fromCounsellor' => $fromCounsellor,
                'toUserId' => $toId,
                'topicId' => $this->therapy_topic_id,
                'confidential' => $this->confidential,
                'status' => $this->status,
                'type' => $this->type,
                'updatedAt' => $this->updated_at,
            ];
        }

        $forType = str_replace('App\Models\\', '', $this->for_type);
        $data = [
            'id' => $this->id,
            'fromUserId' => $maskedFromId,
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
            // Only present when the caller (MessageService) eager-loaded `notes` scoped to the
            // requesting counsellor's own id -- omitted entirely (not just null) for a client
            // viewer, since that eager-load never runs for them (SCRUM-203/TT-2.3b).
            'note' => $this->when(
                $this->relationLoaded('notes'),
                fn () => $this->notes->first() ? new MessageNoteResource($this->notes->first()) : null
            ),
        ];

        if ($forType == 'Discussion') {
            return array_merge($data, [
                'counsellorName' => $counsellor?->getName(),
            ]);
        }

        return $data;
    }
}
