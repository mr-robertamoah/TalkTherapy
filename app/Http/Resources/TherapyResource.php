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
        // TODO load up active session and discussion
        $activeSession = null;
        $activeDiscussion = null;
        $user = $request->user();
        $counsellor = $this->counsellor()->withTrashed()->first();

        if ($user && $this->isParticipant($user))
            $activeSession = $this->getActiveSession($user);

        if ($user?->counsellor)
            $activeDiscussion = $this->getActiveDiscussion($user->counsellor);

        
        return [
            'id' => $this->id,
            'name' => $this->name,
            'user' => $this->when(
                    $this->addedby->is($user) || !$this->anonymous, 
                    new UserMiniResource($this->addedby), 
                    ['id' => $this->addedby->id, 'fullName' => 'anonymous']
                ),
            'public' => (bool) $this->public,
            'anonymous' => (bool) $this->anonymous,
            'allowInPerson' => (bool) $this->allow_in_person,
            'counsellor' => $this->when($counsellor, new CounsellorMiniResource($counsellor)),
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
            'topicsCount' => $this->topicsCount,
            'createdAt' => $this->created_at,
            'activeSession' => $activeSession ? new SessionResource($activeSession) : null,
            'activeDiscussion' => $activeDiscussion ? new DiscussionResource($activeDiscussion) : null,
        ];
    }
}
