<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LicenseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if (is_null($this->resource)) {
            return [];
        }

        return [
            'id' => $this->id,
            'number' => $this->number,
            'validated' => $this->validated,
            // SCRUM-338: set when the documents were deleted under the retention policy, so the
            // admin UI can say so rather than showing an empty license.
            'documentsRemovedAt' => $this->documents_removed_at?->toIso8601String(),
            'licensingAuthority' => new LicensingAuthorityResource($this->licensingAuthority),
            // SCRUM-300: never File::url -- license documents live on a private disk, and
            // getUrlFor() refuses them. Always the authorized retrieval route instead.
            'file' => $this->files->map(fn ($file) => [
                'id' => $file->id,
                'name' => $file->name,
                'mime' => $file->mime,
                'url' => route('licenses.documents.show', ['license' => $this->id, 'file' => $file->id]),
            ])->values(),
        ];
    }
}
