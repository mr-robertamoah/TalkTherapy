<?php

namespace App\Http\Resources;

use App\Models\Counsellor;
use App\Models\User;
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

        if ($user && $this->isParticipant($user)) {
            $activeSession = $this->getActiveSession($user);
        }

        if ($user?->counsellor) {
            $activeDiscussion = $this->getActiveDiscussion($user->counsellor);
        }

        // Anonymity only ever applies to a User (client) addedby, never a Counsellor one, and
        // never masks the owner's own view of their own record (mirrors TherapyResource's
        // `$this->addedby?->is($user) || ! $this->anonymous` precedent).
        $addedbyUser = $this->addedby_type == User::class ? $this->addedby : null;
        $isAnonymous = $addedbyUser && $this->isAnonymousFor($addedbyUser);

        return [
            'id' => $this->id,
            'name' => $this->name,
            // Recognizes pivot-attached members too (SCRUM-69/SCRUM-72), not just the creator
            // or an assigned counsellor -- lets the frontend hide the "join" action for an
            // already-joined member.
            'isParticipant' => $user ? $this->isParticipant($user) : false,
            'addedby' => $this->addedby_type == Counsellor::class
                ? new CounsellorMiniResource($this->addedby)
                : $this->when(
                    $addedbyUser?->is($user) || ! $isAnonymous,
                    new UserMiniResource($this->addedby),
                    ['id' => $this->addedby?->id, 'fullName' => 'Client (Anonymous User)']
                ),
            'public' => (bool) $this->public,
            'anonymous' => (bool) $this->anonymous,
            'allowInPerson' => (bool) $this->allow_in_person,
            'allowAnyone' => (bool) $this->allow_anyone,
            'counsellors' => CounsellorMiniResource::collection($this->counsellors),
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
