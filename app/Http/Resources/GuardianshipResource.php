<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GuardianshipResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isWard = $request->user()->id == $this->guardian_id;

        return [
            'id' => $this->id,
            'ward' => $this->when($isWard, new UserMiniResource(User::find($this->ward_id))),
            'guardian' => $this->when(!$isWard, new UserMiniResource(User::find($this->guardian_id))),
            'createdAt' => $this->created_at->diffForHumans(),
            'isWard' => $isWard,
        ];
    }
}
