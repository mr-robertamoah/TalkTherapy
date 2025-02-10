<?php

namespace App\Http\Resources;

use App\Models\Counsellor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GroupTherapyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $activeSession = null;
        $activeDiscussion = null;
        $user = $request->user();
        $therapyUser = $this->addedby_type == Counsellor::class ?
            $this->addedby->user :
            $this->addedby;

        if ($user && $this->isParticipant($user))
            $activeSession = $this->getActiveSession($user);

        if ($user?->counsellor)
            $activeDiscussion = $this->getActiveDiscussion($user->counsellor);
        
        return [
            'id' => $this->id,
            'name' => $this->name,
            'user' => new UserMiniResource($therapyUser),
            'public' => (bool) $this->public,
            'anonymous' => (bool) $this->anonymous,
            'allowInPerson' => (bool) $this->allow_in_person,
            'allowAnyone' => (bool) $this->allow_anyone,
            'counsellors' =>  CounsellorMiniResource::collection($this->counsellors),
            'about' => $this->about,
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
            'maxUsers' => $this->max_users,
            'maxCounsellors' => $this->max_counsellors,
            'topicsCount' => $this->topicsCount,
            'createdAt' => $this->created_at,
            'activeSession' => $activeSession ? new SessionResource($activeSession) : null,
            'activeDiscussion' => $activeDiscussion ? new DiscussionResource($activeDiscussion) : null,
        ];
    }
}
