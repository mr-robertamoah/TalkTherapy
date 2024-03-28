<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminCounsellorStatsResource extends JsonResource
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
            'numberOfTherapies' => $this->therapiesCount,
            'numberOfGroupTherapies' => $this->groupTherapiesCount,
            'numberOfFreeTherapies' => $this->freeTherapiesCount,
            'numberOfPaidTherapies' => $this->paidTherapiesCount,
            'numberOfFreeGroupTherapies' => $this->freeGroupTherapiesCount,
            'numberOfPaidGroupTherapies' => $this->paidGroupTherapiesCount,
            'numberOfSessionsHeld' => $this->heldSessionsCount,
            'numberOfSessionsCreated' => $this->sessionsCount,
            'numberOfOnlineSessionsCount' => $this->onlineSessionsCount,
            'numberOfInPersonSessionsCount' => $this->inPersonSessionsCount,
        ];
    }
}
