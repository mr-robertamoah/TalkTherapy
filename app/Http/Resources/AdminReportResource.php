<?php

namespace App\Http\Resources;

use App\Actions\Report\ResolveReportUnlockScopeAction;
use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Models\ConfidentialMessageAccess;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Post;
use App\Models\ReportStatusHistory;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// SCRUM-340: a report as an admin reviewing it sees it. Only built by AdminReportController, which
// is admin-only. `detailed` adds what the review page needs (history, participants, sessions that
// can be unlocked, and the access log) -- the list page doesn't load any of that.
class AdminReportResource extends JsonResource
{
    public bool $detailed = false;

    public function detailed(): static
    {
        $this->detailed = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        // Security review: in the queue, an admin who is part of a report sees only that it exists
        // (AdminReportController::show refuses them the page).
        if (! $this->detailed && $request->user() && $this->involves($request->user())) {
            return [
                'id' => $this->id,
                'status' => $this->status->value,
                'involved' => true,
                'createdAt' => $this->created_at,
            ];
        }

        $therapy = $this->therapy();
        $reported = $this->reportedPerson();

        $data = [
            'involved' => false,
            'id' => $this->id,
            'status' => $this->status->value,
            'outcome' => $this->outcome?->value,
            'resolutionNote' => $this->resolution_note,
            'resolvedAt' => $this->resolved_at,
            'description' => $this->description,
            'reporter' => $this->person($this->addedby),
            'reportedPerson' => $reported,
            'subject' => $this->subject(),
            'therapy' => $therapy ? [
                'type' => $therapy instanceof GroupTherapy ? 'group' : 'individual',
                'id' => $therapy->id,
                'name' => $therapy->name,
            ] : null,
            'createdAt' => $this->created_at,
        ];

        if (! $this->detailed) {
            return $data;
        }

        $scope = ResolveReportUnlockScopeAction::new()->execute($this->resource);

        return [
            ...$data,
            'files' => $this->files->map(fn ($file) => [
                'id' => $file->id,
                'name' => $file->name,
                'mime' => $file->mime,
                'url' => route('reports.evidence.show', ['report' => $this->id, 'file' => $file->id]),
            ])->values()->all(),
            'history' => $this->histories->map(fn (ReportStatusHistory $history) => [
                'id' => $history->id,
                'event' => $history->event,
                'fromStatus' => $history->from_status,
                'toStatus' => $history->to_status,
                'outcome' => $history->outcome,
                'note' => $history->note,
                'by' => $history->user?->name ?? 'a deleted account',
                'at' => $history->created_at,
            ])->values()->all(),
            'participants' => $therapy ? $this->participantsOf($therapy) : [],
            'unlock' => [
                'available' => ! $scope->isEmpty(),
                'reason' => $this->unlockUnavailableReason($scope->isEmpty(), $therapy),
                'sessions' => $scope->isEmpty() ? [] : Session::query()
                    ->whereKey($scope->sessionIds)
                    ->orderByDesc('start_time')
                    ->get(['id', 'name', 'status', 'start_time'])
                    ->map(fn (Session $session) => [
                        'id' => $session->id,
                        'name' => $session->name,
                        'status' => $session->status,
                        'startTime' => $session->start_time,
                    ])->all(),
            ],
            // AdminReportController::show loads only the session-open rows, plus a count of the rest.
            'accessLog' => $this->confidentialAccesses
                ->map(fn (ConfidentialMessageAccess $access) => [
                    'id' => $access->id,
                    'admin' => $access->admin?->name ?? 'a deleted account',
                    'sessionId' => $access->session_id,
                    'reason' => $access->reason,
                    'at' => $access->created_at,
                ])->values()->all(),
            'confidentialMessagesRead' => (int) $this->confidential_reads_count,
        ];
    }

    private function reportedPerson(): ?array
    {
        return $this->person($this->reportedParty());
    }

    private function person(User|Counsellor|null $person): ?array
    {
        if (! $person) {
            return null;
        }

        $isCounsellor = $person instanceof Counsellor;

        return [
            'type' => $isCounsellor ? 'counsellor' : 'user',
            'id' => $person->id,
            'name' => $isCounsellor ? $person->getName() : $person->name,
            'username' => $isCounsellor ? $person->user?->username : $person->username,
        ];
    }

    private function subject(): array
    {
        $reportable = $this->reportable;

        return [
            'type' => match (true) {
                $reportable instanceof Session => 'session',
                $reportable instanceof Therapy => 'therapy',
                $reportable instanceof GroupTherapy => 'group therapy',
                $reportable instanceof Post => 'post',
                default => $this->reportable_type ? strtolower(class_basename($this->reportable_type)) : 'unknown',
            },
            'id' => $this->reportable_id,
            'name' => $reportable instanceof Post ? null : $reportable?->name,
        ];
    }

    // Everyone the admin may record as the reported person.
    private function participantsOf(Therapy|GroupTherapy $therapy): array
    {
        $people = collect();

        if ($therapy->addedby instanceof User) {
            $people->push([...$this->person($therapy->addedby), 'role' => 'client']);
        } elseif ($therapy->addedby instanceof Counsellor) {
            $people->push([...$this->person($therapy->addedby), 'role' => 'counsellor']);
        }

        if ($therapy instanceof Therapy && $therapy->counsellor) {
            $people->push([...$this->person($therapy->counsellor), 'role' => 'counsellor']);
        }

        if ($therapy instanceof GroupTherapy) {
            $therapy->counsellors()->wherePivot('state', CounsellorGroupTherapyStateEnum::active->value)->get()->each(fn (Counsellor $c) => $people->push([...$this->person($c), 'role' => 'counsellor']));
            $therapy->users()->get()->each(fn (User $u) => $people->push([...$this->person($u), 'role' => 'member']));
        }

        $reporter = $this->addedby;

        return $people
            ->unique(fn ($p) => $p['type'].$p['id'])
            ->reject(fn ($p) => $reporter && $p['type'] == ($reporter instanceof Counsellor ? 'counsellor' : 'user') && $p['id'] == $reporter->id)
            ->values()
            ->all();
    }

    private function unlockUnavailableReason(bool $empty, $therapy): ?string
    {
        if (! $empty) {
            return null;
        }

        return match (true) {
            ! $this->isActive() => 'Confidential messages can only be read while the report is open or under review.',
            ! $therapy => 'This report is not about a therapy conversation, so there are no confidential messages to read.',
            ! $this->reportedParty() => 'Record who this report is about first. Only messages that person sent or received can be read.',
            default => 'There are no sessions in this conversation yet.',
        };
    }
}
