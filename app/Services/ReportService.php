<?php

namespace App\Services;

use App\Actions\EnsureAddedbyIsValidAction;
use App\Actions\Report\CreateReportAction;
use App\Actions\Report\DefaultReportedPartyAction;
use App\Actions\Report\DeleteReportAction;
use App\Actions\Report\EnsureAdminCanReviewReportAction;
use App\Actions\Report\EnsureAdminCanViewReportedAttachmentAction;
use App\Actions\Report\EnsureCanCreateReportAction;
use App\Actions\Report\EnsureCanReportReportableAction;
use App\Actions\Report\EnsureCanUpdateReportAction;
use App\Actions\Report\EnsureReportDataIsValidAction;
use App\Actions\Report\EnsureReportExistsAction;
use App\Actions\Report\ResolveReportUnlockScopeAction;
use App\Actions\Report\TransitionReportStatusAction;
use App\Actions\Report\UpdateReportAction;
use App\DTOs\CreateReportDTO;
use App\Enums\PaginationEnum;
use App\Enums\ReportStatusEnum;
use App\Exceptions\ReportException;
use App\Http\Resources\AdminReportedMessageResource;
use App\Models\ConfidentialMessageAccess;
use App\Models\Counsellor;
use App\Models\File;
use App\Models\GroupTherapy;
use App\Models\HelpRequest;
use App\Models\Message;
use App\Models\Report;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Support\AdminSearch;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;

class ReportService extends Service
{
    public const CONVERSATION_NOT_AVAILABLE = 'This conversation is not available for this report.';

    public function createReport(CreateReportDTO $createReportDTO)
    {
        EnsureAddedbyIsValidAction::new()->execute(
            $createReportDTO,
            'You are not allowed to use the account to add the report.'
        );

        EnsureCanCreateReportAction::new()->execute($createReportDTO);

        EnsureCanReportReportableAction::new()->execute($createReportDTO);

        DefaultReportedPartyAction::new()->execute($createReportDTO);

        EnsureReportDataIsValidAction::new()->execute($createReportDTO);

        $report = CreateReportAction::new()->execute($createReportDTO);

        AppService::new()->alertAdminWithReport($report);

        return $report;
    }

    public function updateReport(CreateReportDTO $createReportDTO)
    {
        EnsureReportExistsAction::new()->execute($createReportDTO);

        EnsureCanUpdateReportAction::new()->execute($createReportDTO);

        // SCRUM-347: a reporter can't re-point their report at a therapy they aren't in.
        EnsureCanReportReportableAction::new()->execute($createReportDTO, required: false);

        EnsureReportDataIsValidAction::new()->execute($createReportDTO, 'update');

        return UpdateReportAction::new()->execute($createReportDTO);
    }

    public function deleteReport(CreateReportDTO $createReportDTO)
    {
        EnsureReportExistsAction::new()->execute($createReportDTO);

        EnsureCanUpdateReportAction::new()->execute($createReportDTO);

        return DeleteReportAction::new()->execute($createReportDTO);
    }

    public function getReport(CreateReportDTO $createReportDTO)
    {
        // SCRUM-337: admins and the reporter only, and a missing report gets the same answer.
        if (! $createReportDTO->report?->isViewableBy($createReportDTO->user)) {
            throw new ReportException('Report not found.', 404);
        }

        return $createReportDTO->report;
    }

    public function getReports(CreateReportDTO $createReportDTO)
    {
        $query = Report::query();

        // SCRUM-337: this listed every report to anyone signed in. Admins see all; everyone else
        // only their own, whatever addedby/reportable filter they pass.
        if (! $createReportDTO->user->isAdmin()) {
            $query->whereReportedBy($createReportDTO->user);
        }

        $query->when($createReportDTO->addedby, function ($query) use ($createReportDTO) {
            $query->whereAddedby($createReportDTO->addedby);
        });

        $query->when($createReportDTO->reportable, function ($query) use ($createReportDTO) {
            $query->whereReportable($createReportDTO->reportable);
        });

        // ReportResource lists each report's evidence, and (SCRUM-362) asks involves() of each row.
        $query->with([
            'files',
            'addedby' => fn (MorphTo $morph) => $morph->morphWith([Counsellor::class => ['user']]),
            'reportable' => fn (MorphTo $morph) => $morph->morphWith([Session::class => ['for'], HelpRequest::class => ['user']]),
        ])->orderByDesc('created_at');

        return $query->paginate(PaginationEnum::preferencesPagination->value);
    }

    // SCRUM-340: the admin review queue -- active reports (open first, oldest first) unless a status
    // is asked for. Loads what AdminReportResource reads for every row.
    // SCRUM-390: [page, more than AdminSearch::CAP matches]. $search: the reporter's names and
    // username, or the reported therapy's or group's name; a report the viewer is involved in
    // (shown only as "involved") never matches.
    public function getReportsForAdmin(?ReportStatusEnum $status, ?string $search = null, ?User $viewer = null): array
    {
        // A search must know who is looking, or it could match reports they're involved in.
        if ($search && ! $viewer) {
            throw new \InvalidArgumentException('A report search needs the viewer.');
        }

        $therapyName = fn ($query) => $query->whereContains('name', $search);

        $query = Report::query()
            ->when(
                $status,
                fn ($q) => $q->where('status', $status->value),
                fn ($q) => $q->whereIn('status', [ReportStatusEnum::open->value, ReportStatusEnum::under_review->value])
            )
            ->when($search, fn ($query, $term) => $query->where(fn ($query) => $query
                ->whereHasMorph('addedby', [User::class], fn ($query) => AdminSearch::person($query, $term))
                ->orWhereHasMorph('addedby', [Counsellor::class], fn ($query) => $query->whereHas('user', fn ($query) => AdminSearch::person($query, $term)))
                ->orWhereHasMorph('reportable', [Therapy::class, GroupTherapy::class], $therapyName)
                ->orWhereHasMorph('reportable', [Session::class], fn ($query) => $query->whereHasMorph('for', [Therapy::class, GroupTherapy::class], $therapyName))))
            ->with([
                'addedby' => fn (MorphTo $morph) => $morph->morphWith([Counsellor::class => ['user']]),
                'reportable' => fn (MorphTo $morph) => $morph->morphWith([Session::class => ['for'], HelpRequest::class => ['user']]),
            ]);

        $tooMany = $search ? AdminSearch::withoutInvolved($query, $viewer, fn (Report $report, User $viewer) => $report->involves($viewer)) : false;

        return [
            $query->orderByRaw("status = 'open' desc")
                ->orderBy('created_at')
                ->paginate(PaginationEnum::homePagination->value)
                ->withQueryString(),
            $tooMany,
        ];
    }

    // SCRUM-340: an admin reads a reported conversation, giving a reason. Everything is decided and
    // recorded from the report row held under a lock, so a concurrent close, re-point or edit can't
    // leave a read made under a stale scope. Content participants can't currently see (confidential
    // or deleted for everyone) is shown only within the scope and each such message is recorded,
    // along with one row for opening the session. The first unlock moves an open report to
    // under_review.
    public function unlockConversation(User $admin, ?Report $report, ?Session $session, string $reason): array
    {
        EnsureAdminCanReviewReportAction::new()->execute($admin, $report);

        return DB::transaction(function () use ($admin, $report, $session, $reason) {
            $locked = Report::query()->lockForUpdate()->findOrFail($report->id);
            $scope = ResolveReportUnlockScopeAction::new()->execute($locked);

            if (! $scope->allowsSession($session)) {
                throw new ReportException(self::CONVERSATION_NOT_AVAILABLE, 404);
            }

            $messages = Message::withTrashed()
                ->where('for_type', Session::class)
                ->where('for_id', $session->id)
                ->with('files')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            $revealed = $messages
                ->filter(fn (Message $message) => EnsureAdminCanViewReportedAttachmentAction::isRestricted($message) && $scope->reveals($message))
                ->pluck('id')
                ->all();

            ConfidentialMessageAccess::record($locked, $admin, $session->id, $reason);
            if ($revealed) {
                ConfidentialMessageAccess::record($locked, $admin, $session->id, $reason, $revealed);
            }

            if ($locked->status == ReportStatusEnum::open) {
                TransitionReportStatusAction::new()->execute($admin, $locked, ReportStatusEnum::under_review);
            }

            return $messages->map(fn (Message $message) => AdminReportedMessageResource::present($message, $locked, $scope))->all();
        });
    }

    // SCRUM-340: authorizes an attachment of an unlocked conversation (see
    // EnsureAdminCanViewReportedAttachmentAction) and records each restricted one opened.
    public function authorizeReportedAttachment(User $admin, ?Report $report, ?Message $message, ?File $file): void
    {
        EnsureAdminCanReviewReportAction::new()->execute($admin, $report);

        $scope = ResolveReportUnlockScopeAction::new()->execute($report);
        $session = EnsureAdminCanViewReportedAttachmentAction::new()->execute($admin, $report, $scope, $message, $file);

        if (EnsureAdminCanViewReportedAttachmentAction::isRestricted($message)) {
            ConfidentialMessageAccess::record($report, $admin, $session->id, 'Opened an attachment of an unlocked message.', [$message->id], $file->id);
        }
    }
}
