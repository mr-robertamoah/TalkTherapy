<?php

namespace App\Http\Resources;

use App\Models\Counsellor;
use App\Models\Therapy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SessionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currentTopic = $this->currentTopic;
        $isIndividualTherapy = $this->for_type === Therapy::class;

        return [
            'id' => $this->id,
            'userId' => $this->addedby_type == Counsellor::class ? $this->addedby->user->id : $this->addedby_id,
            'updatedById' => $this->updatedby_type == Counsellor::class ? $this->updatedby->user_id : $this->updatedby_id,
            'name' => $this->name,
            'about' => $this->about,
            'type' => $this->type,
            'lng' => $this->longitude,
            'lat' => $this->latitude,
            'status' => $this->status,
            'topics' => TherapyTopicMiniResource::collection($this->topics),
            'currentTopic' => $currentTopic ? new TherapyTopicMiniResource($currentTopic) : null,
            'cases' => TherapyCaseResource::collection($this->cases),
            'startTime' => $this->start_time,
            'endTime' => $this->end_time,
            'paymentType' => $this->payment_type,
            'paymentStatus' => $this->latestTransaction?->status,
            'landmark' => $this->landmark,
            'isSession' => true,
            'createdAt' => $this->created_at,
            // SCRUM-212: only present when the caller eager-loaded `for` -- the counsellor
            // calendar aggregate is the first consumer that needs to know which Therapy/
            // GroupTherapy a session belongs to; every other existing call site already knows its
            // one parent from context and never eager-loads this, so this stays a MissingValue
            // (omitted) for them.
            'for' => $this->whenLoaded('for', fn () => $isIndividualTherapy
                ? new TherapyMiniResource($this->for)
                : new GroupTherapyMiniResource($this->for)),
            // SCRUM-213/TT-2.6b: neither mini resource above exposes an explicit discriminator,
            // and the calendar UI needs one to route a click through to the right page
            // (therapies.get vs group.therapies.get) without inferring it from field presence.
            'forType' => $this->whenLoaded('for', fn () => $isIndividualTherapy ? 'individual' : 'group'),
        ];
    }
}
