<?php

namespace App\Http\Controllers;

use App\Actions\Report\EnsureAdminCanReviewReportAction;
use App\Actions\Report\EnsureAdminCanViewReportedAttachmentAction;
use App\Actions\Report\RecordReportedPartyAction;
use App\Actions\Report\TransitionReportStatusAction;
use App\Enums\ReportOutcomeEnum;
use App\Enums\ReportStatusEnum;
use App\Http\Resources\AdminReportResource;
use App\Models\File;
use App\Models\Message;
use App\Models\Report;
use App\Models\Session;
use App\Services\ReportService;
use App\Traits\ResolvesExceptionResponse;
use App\Traits\StreamsPrivateDocuments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

// SCRUM-340: the admin complaint-review surface -- a queue of reports, a page per report, its
// status/party changes, and reading the reported conversation's confidential messages. The
// conversation comes back over JSON (never as Inertia page props, so it isn't kept in history
// state) with no-store caching. Every action authorizes before validating or looking anything up,
// so a non-admin always gets the same 404.
class AdminReportController extends Controller
{
    use ResolvesExceptionResponse, StreamsPrivateDocuments;

    public function index(Request $request)
    {
        if ($request->user()?->isNotAdmin() ?? true) {
            return Redirect::route('home')->with('message', 'You are not authorized to visit this page.');
        }

        $status = ReportStatusEnum::tryFrom((string) $request->query('status'));

        return Inertia::render('Admin/Reports', [
            'reports' => AdminReportResource::collection(ReportService::new()->getReportsForAdmin($status))->response()->getData(true),
            'status' => $status?->value,
            'statuses' => ReportStatusEnum::values(),
        ]);
    }

    public function show(Request $request, int $report)
    {
        $model = Report::query()->with(['addedby', 'reportable', 'files', 'histories.user'])->find($report);

        try {
            // Security review: an admin who is part of the report doesn't get the page at all --
            // it names the reporter and the reviewing admins.
            EnsureAdminCanReviewReportAction::new()->execute($request->user(), $model);
        } catch (Throwable $th) {
            return Redirect::route($request->user()?->isAdmin() ? 'administrator.reports' : 'home')
                ->with('message', $this->messageFor($th, $this->statusFor($th)));
        }

        $model->load(['confidentialAccesses' => fn ($q) => $q->whereNull('message_id')->with('admin')])
            ->loadCount(['confidentialAccesses as confidential_reads_count' => fn ($q) => $q->whereNotNull('message_id')->whereNull('file_id')]);

        return Inertia::render('Admin/ReportShow', [
            'report' => (new AdminReportResource($model))->detailed()->resolve($request),
            'outcomes' => ReportOutcomeEnum::values(),
        ]);
    }

    public function transition(Request $request, int $report)
    {
        try {
            $model = $this->reviewable($request, $report);
            $validated = $request->validate([
                'status' => ['required', Rule::in(ReportStatusEnum::values())],
                'outcome' => ['nullable', Rule::in(ReportOutcomeEnum::values())],
                'note' => ['nullable', 'string', 'max:2000'],
            ]);

            TransitionReportStatusAction::new()->execute(
                $request->user(),
                $model,
                ReportStatusEnum::from($validated['status']),
                ReportOutcomeEnum::tryFrom((string) ($validated['outcome'] ?? '')),
                $validated['note'] ?? null,
            );

            return Redirect::back()->with('message', 'Report updated.');
        } catch (Throwable $th) {
            return $this->redirectWithFailure($th);
        }
    }

    public function recordParty(Request $request, int $report)
    {
        try {
            $model = $this->reviewable($request, $report);
            $validated = $request->validate([
                'type' => ['required', Rule::in(['user', 'counsellor'])],
                'id' => ['required', 'integer'],
            ]);

            RecordReportedPartyAction::new()->execute($request->user(), $model, $validated['type'], (int) $validated['id']);

            return Redirect::back()->with('message', 'Reported person recorded.');
        } catch (Throwable $th) {
            return $this->redirectWithFailure($th);
        }
    }

    public function conversation(Request $request, int $report, int $session)
    {
        try {
            $model = $this->reviewable($request, $report);
            $validated = $request->validate([
                'reason' => ['required', 'string', 'min:10', 'max:1000'],
            ]);

            $messages = ReportService::new()->unlockConversation($request->user(), $model, Session::find($session), trim($validated['reason']));

            return response()->json(['messages' => $messages])->header('Cache-Control', 'private, no-store');
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $th) {
            $status = $this->statusFor($th);

            return response()->json(['status' => false, 'error' => $this->messageFor($th, $status)], $status);
        }
    }

    public function attachment(Request $request, int $report, int $message, int $file)
    {
        $fileModel = File::find($file);

        // Authorized before the file lookup decides anything: a missing file and a refusal answer alike.
        return $this->authorizedDocumentResponse(
            function () use ($request, $report, $message, $fileModel) {
                ReportService::new()->authorizeReportedAttachment(
                    $request->user(),
                    Report::find($report),
                    Message::withTrashed()->find($message),
                    $fileModel,
                );
            },
            $fileModel?->storage ?? 'message_attachments',
            $fileModel ?? new File,
            EnsureAdminCanViewReportedAttachmentAction::NOT_FOUND,
            self::INLINE_MEDIA_TYPES,
        );
    }

    // A missing report, a non-admin and an involved admin are refused before anything else runs.
    private function reviewable(Request $request, int $report): Report
    {
        $model = Report::find($report);
        EnsureAdminCanReviewReportAction::new()->execute($request->user(), $model);

        return $model;
    }

    private function redirectWithFailure(Throwable $th)
    {
        if ($th instanceof ValidationException) {
            throw $th;
        }

        return Redirect::back()->withErrors(['alert' => $this->messageFor($th, $this->statusFor($th))]);
    }
}
