<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TherapyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'user' => $this->when($this->addedBy->is($request->user()) || !$this->anonymous, new UserMiniResource($this->addedBy)),
            'public' => (bool) $this->public,
            'anonymous' => (bool) $this->anonymous,
            'allowInPerson' => (bool) $this->alow_in_persion,
            'counsellor' => $this->when($this->counsellor, new CounsellorMiniResource($this->counsellor)),
            'backgroundStory' => $this->background_story,
            'sessionsHeld' => $this->sessionsHeld,
            'status' => $this->getStatus(),
            'paymentData' => $this->payment_data,
            'sessionsCreated' => $this->sessionsCreated,
            'paymentType' => $this->payment_type,
            'sessionType' => $this->session_type,
            'paidSessions' => $this->paidSessions,
            'freeSessions' => $this->freeSessions,
            'cases' => TherapyCaseResource::collection($this->cases),
            'maxSessions' => $this->max_sessions,
            'createdAt' => $this->created_at->diffForHumans()
        ];
    }
}
