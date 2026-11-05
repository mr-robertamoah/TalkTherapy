<?php

namespace App\Actions\Report;

use App\Actions\Action;
use App\DTOs\CreateReportDTO;
use App\Exceptions\ReportException;
use App\Models\GroupTherapy;
use App\Models\Post;
use App\Models\Session;
use App\Models\Therapy;

// SCRUM-347: only a therapy's (or its session's) participants (SCRUM-73: and a group's former
// members) may report it. Before this, anyone
// signed in could report any therapy id, and the "not participating" errors told them who was in
// it. A missing or someone else's therapy gets the same answer. Posts are public, so anyone may
// report one.
class EnsureCanReportReportableAction extends Action
{
    public const NOT_ALLOWED = 'You can only report a therapy or session you take part in.';

    public function execute(CreateReportDTO $createReportDTO, bool $required = true): void
    {
        $reportable = $createReportDTO->reportable;

        if (! $reportable && ! $required) {
            return;
        }

        if ($reportable instanceof Post) {
            return;
        }

        $isParticipant = ($reportable instanceof Therapy || $reportable instanceof GroupTherapy || $reportable instanceof Session)
            && $reportable->isParticipant($createReportDTO->user);

        // SCRUM-73 (the user's decision, 2026-11-05): someone who left a group may still report it
        // and its sessions -- what happened while they were in it, so a session only if it was
        // created before they left.
        $group = $reportable instanceof Session ? $reportable->for : $reportable;
        $wasMember = $group instanceof GroupTherapy && ($reportable instanceof Session
            ? $group->allMemberships()->whereKey($createReportDTO->user->id)->wherePivot('left_at', '>=', $reportable->created_at)->exists()
            : $group->hasEverBeenMember($createReportDTO->user));

        if (! $isParticipant && ! $wasMember) {
            throw new ReportException(self::NOT_ALLOWED, 422);
        }
    }
}
