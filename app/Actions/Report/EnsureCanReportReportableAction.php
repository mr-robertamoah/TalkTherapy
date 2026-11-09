<?php

namespace App\Actions\Report;

use App\Actions\Action;
use App\Actions\Help\RingAvailableCounsellorsAction;
use App\DTOs\CreateReportDTO;
use App\Enums\HelpRequestStatusEnum;
use App\Exceptions\ReportException;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\HelpRequest;
use App\Models\Post;
use App\Models\Report;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;

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

        if ($reportable instanceof HelpRequest) {
            $this->ensureMayReportHelpRequest($createReportDTO->user, $reportable);

            return;
        }

        // SCRUM-398 (the architect confirmed a leak): an urgent help chat is never reported through here --
        // its Therapy or Session would show admins who asked, anonymous or not, with no read log. It is
        // reported as the HelpRequest (help.requests.report), whose rules mask who asked. Same answer as
        // anything else the reporter may not report.
        $helpChat = $reportable instanceof Session ? $reportable->for : $reportable;
        if ($helpChat instanceof Therapy && $helpChat->isHelpChat()) {
            throw new ReportException(self::NOT_ALLOWED, 422);
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

    public const HELP_NOT_ALLOWED = 'You can only report an urgent help request you answered, or one that is ringing you now.';

    public const HELP_ALREADY_REPORTED = 'You have already reported this request.';

    // SCRUM-398: for the help chat and the ring to offer "Report" -- the same rule, answered yes or no.
    public function mayReportHelpRequest(?User $user, HelpRequest $help): bool
    {
        try {
            $this->ensureMayReportHelpRequest($user, $help);

            return true;
        } catch (ReportException) {
            return false;
        }
    }

    // SCRUM-398 (the user's decision): the counsellor who answered, until the chat's messages are deleted;
    // or a counsellor it is ringing now (nothing records who was rung once the ring is over). Once each.
    // Everyone else -- the person who asked, a stranger, a missing request -- gets the same answer.
    private function ensureMayReportHelpRequest(?User $user, HelpRequest $help): void
    {
        $counsellor = $user?->counsellor;
        $answered = $counsellor && $help->isAnsweredBy($user) && ! $help->transcript_deleted_at;
        $ringing = $counsellor && $help->status === HelpRequestStatusEnum::waiting && RingAvailableCounsellorsAction::mayAnswer($help, $counsellor);

        if (! $answered && ! $ringing) {
            throw new ReportException(self::HELP_NOT_ALLOWED, 403);
        }

        $already = Report::query()->where('reportable_type', HelpRequest::class)->where('reportable_id', $help->id)
            ->where(fn ($query) => $query->where(fn ($query) => $query->where('addedby_type', Counsellor::class)->where('addedby_id', $counsellor->id))
                ->orWhere(fn ($query) => $query->where('addedby_type', User::class)->where('addedby_id', $user->id)))
            ->exists();
        if ($already) {
            throw new ReportException(self::HELP_ALREADY_REPORTED, 422);
        }
    }
}
