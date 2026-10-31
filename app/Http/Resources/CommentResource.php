<?php

namespace App\Http\Resources;

use App\Enums\ConstantsEnum;
use App\Models\GroupTherapy;
use App\Models\Therapy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // SCRUM-354: on a therapy or group, a comment by one of its own anonymous people (its
        // anonymous client, an anonymous member) shows the anonymous label -- except to themselves,
        // who still see (and can delete) their own comment. A public route, so the viewer comes from
        // the SPA session when there is one.
        $viewer = $request->user() ?? $request->user('sanctum');
        $commentable = $this->commentable;
        // `anonymous` is recorded when the comment is written, so leaving the group or the therapy
        // dropping anonymity later never unmasks it; the live check covers comments from before
        // the column existed.
        $isMasked = $this->user
            && ! $this->user->is($viewer)
            && (
                (bool) $this->anonymous
                || (($commentable instanceof Therapy || $commentable instanceof GroupTherapy) && $commentable->isAnonymousParticipant($this->user))
            );

        return [
            'id' => $this->id,
            'content' => $this->content,
            'userId' => $isMasked ? null : $this->user_id,
            'username' => $isMasked ? ConstantsEnum::anonymousUserLabel->value : $this->user?->username,
            'createdAt' => $this->created_at->diffForHumans(),
        ];
    }
}
