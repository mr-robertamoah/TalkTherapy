<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CounsellorResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $contactVisible = $this->contact_visible ||
            $this->user->is($request->user()) ||
            $request->user()?->isAdmin();

        return [
            'id' => $this->id,
            'userId' => $this->user_id,
            'name' => $this->getName(),
            'about' => $this->about,
            'profession' => $this->when($this->profession, new ProfessionResource($this->profession)),
            'phone' => $this->when($contactVisible, $this->phone),
            'email' => $this->when($contactVisible, $this->email),
            'avatar' => $this->avatar?->url,
            'cover' => $this->cover?->url,
            'contactVisible' => (bool) $this->contact_visible,
            'emailVerified' => (bool) $this->email_verified_at,
            'verified' => (bool) $this->verified_at,
            'overallStarsCount' => $this->overallStarsCount,
            'currentMonthStarsCount' => $this->currentMonthStarsCount,
            // TODO therapies
            'cases' => $this->when($this->cases, TherapyCaseResource::collection($this->cases), []),
            'religions' => $this->when($this->religions, TherapyCaseResource::collection($this->religions), []),
            'languages' => $this->when($this->languages, TherapyCaseResource::collection($this->languages), []),
            'createdAt' => $this->created_at->diffForHumans(),
            'freeTherapiesCount' => $this->freeTherapiesCount,
            'paidTherapiesCount' => $this->paidTherapiesCount,
            'groupTherapiesCount' => $this->groupTherapiesCount,
            'onlineSessionsHeldCount' => $this->onlineSessionsHeldCount,
            'inPersonSessionsCount' => $this->inPersonSessionsCount,
            'hasNationalIdentification' => $this->hasNationalIdentification(),
            'hasPendingCounsellorVerificationRequest' => $this->hasPendingCounsellorVerificationRequest()
        ];
    }
}
