<?php

namespace App\Http\Resources;

use App\Models\Counsellor;
use App\Models\License;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminCounsellorVerificationRequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Request::from() is a generic morphTo() shared across every RequestTypeEnum case, so
        // it isn't given withTrashed() globally (see SCRUM-61). `from` is expected to always be
        // a Counsellor for this request type, so resolve it directly with withTrashed() here
        // instead -- the counsellor who submitted this verification request may have since
        // deleted their account. Guarding on from_type first (rather than assuming) means a
        // request misrouted to this resource with a non-Counsellor `from` degrades to a null
        // counsellor instead of silently returning an unrelated Counsellor row that happens to
        // share the same numeric id.
        $counsellor = $this->from_type === Counsellor::class
            ? Counsellor::withTrashed()->find($this->from_id)
            : null;

        return [
            'from' => $counsellor?->getName(),
            'counsellor' => [
                'id' => $counsellor?->id,
                'name' => $counsellor?->getName(),
                'username' => $counsellor?->user?->username,
                'profession' => $this->when($counsellor?->profession, new ProfessionResource($counsellor?->profession)),
                'phone' => $counsellor?->phone,
                'email' => $counsellor?->email,
                'avatar' => $counsellor?->avatar?->url,
            ],
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'nationalIdLicense' => new LicenseResource(License::find($this->data['nationalIdLicense'] ?? null)),
            'otherLicense' => new LicenseResource(License::find($this->data['otherLicense'] ?? null)),
        ];
    }
}
