<?php

namespace App\Http\Resources;

use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
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
        // SCRUM-362: the JSON list (api.reports) shows every report to an admin -- but one they are
        // part of (and didn't file themselves) only as that it exists, as in the review queue.
        $viewer = $request->user();
        if ($viewer?->isAdmin() && ! $this->isReportedBy($viewer) && $this->involves($viewer)) {
            return $this->resource->involvedSummary();
        }

        return [
            'id' => $this->id,
            'description' => $this->description,
            // SCRUM-340: the reporter can see whether their report is being looked at (and can no
            // longer edit it once it isn't open). Outcome notes are for admins only.
            'status' => $this->status?->value,
            'data' => $this->presentedData($request->user()),
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

    // Security review (SCRUM-348): a counsellor's report on their anonymous client stores the
    // client's real id (DefaultReportedPartyAction). Only admins see it; the reporter sees that the
    // client was reported, never which user id that is.
    private function presentedData(?User $viewer): ?array
    {
        $data = $this->data;
        $therapy = $this->reportable instanceof Session ? $this->reportable->for : $this->reportable;

        // SCRUM-398: a report about an urgent help chat (made before such reports were refused) never shows
        // who asked by id, to anyone -- admins reveal it through the logged help path.
        if (is_array($data) && isset($data['userId']) && $therapy instanceof Therapy && $therapy->isHelpChat()) {
            return [...$data, 'userId' => null, 'reportedClient' => true];
        }

        if (! is_array($data) || ! isset($data['userId']) || $viewer?->isAdmin()) {
            return $data;
        }

        if (($therapy instanceof Therapy || $therapy instanceof GroupTherapy) && $therapy->addedByUserIsMaskedFor($viewer)) {
            $data['userId'] = null;
            $data['reportedClient'] = true;
        }

        return $data;
    }
}
