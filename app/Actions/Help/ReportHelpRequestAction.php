<?php

namespace App\Actions\Help;

use App\Actions\Action;
use App\Actions\Report\EnsureCanReportReportableAction;
use App\DTOs\CreateReportDTO;
use App\Exceptions\ReportException;
use App\Models\HelpRequest;
use App\Models\Report;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Support\Facades\Cache;

// SCRUM-398: a counsellor reports an urgent help request (a prank, abuse) -- into the one report pipeline
// (ReportService::createReport), as the HelpRequest itself, never its chat's therapy or session: that is what
// keeps who asked masked for administrators (Report::masksReportedIdentity). Who may report is decided in
// EnsureCanReportReportableAction. It changes nothing for the person: the request isn't cancelled, nobody
// stops being rung, the chat goes on, and they're never told.
class ReportHelpRequestAction extends Action
{
    public const REASONS = [
        'prank' => 'Prank, or not a real request',
        'abuse' => 'Abusive or threatening',
        'other' => 'Something else',
    ];

    public function execute(?User $counsellorUser, ?HelpRequest $request, string $reason, ?string $details = null): Report
    {
        // A missing request, or someone who isn't a counsellor: the same answer as any other refusal.
        if (! $request || ! $counsellorUser?->counsellor) {
            throw new ReportException(EnsureCanReportReportableAction::HELP_NOT_ALLOWED, 403);
        }
        if (! array_key_exists($reason, self::REASONS)) {
            throw new ReportException('Choose what is wrong with the request.', 422);
        }

        $details = trim((string) $details);

        // Once each: a double submit waits here, then finds the first report (HELP_ALREADY_REPORTED).
        return Cache::lock("help-report:{$request->id}:{$counsellorUser->id}", 10)->block(5, fn () => ReportService::new()->createReport(CreateReportDTO::new()->fromArray([
            'user' => $counsellorUser,
            'addedby' => $counsellorUser->counsellor,
            'reportable' => $request,
            'description' => self::REASONS[$reason].($details !== '' ? "\n\n".$details : ''),
            'data' => [],
        ])));
    }
}
