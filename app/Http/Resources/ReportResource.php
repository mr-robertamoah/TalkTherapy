<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// SCRUM-337: an explicit shape (this used to be parent::toArray(), a raw model dump). Only ever
// built for admins or the reporter -- ReportService gates every read. Evidence is never a File::url:
// it lives on the private report_evidence disk and is served by reports.evidence.show.
class ReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            'data' => $this->data,
            'addedbyType' => $this->addedby_type ? class_basename($this->addedby_type) : null,
            'addedbyId' => $this->addedby_id,
            'reportableType' => $this->reportable_type ? class_basename($this->reportable_type) : null,
            'reportableId' => $this->reportable_id,
            'files' => $this->files->map(fn ($file) => [
                'id' => $file->id,
                'name' => $file->name,
                'mime' => $file->mime,
                'url' => route('reports.evidence.show', ['report' => $this->id, 'file' => $file->id]),
            ])->values()->all(),
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
