<?php

namespace App\Http\Controllers;

use App\Actions\Report\EnsureAdminCanReviewReportAction;
use App\Actions\Suspension\ApplySuspensionAction;
use App\Actions\Suspension\DecideSuspensionAppealAction;
use App\Actions\Suspension\EnsureCanManageSuspensionAction;
use App\Actions\Suspension\LiftSuspensionAction;
use App\Enums\SuspensionAppealDecisionEnum;
use App\Enums\SuspensionScopeEnum;
use App\Http\Resources\AdminSuspensionResource;
use App\Models\Counsellor;
use App\Models\Report;
use App\Models\Session;
use App\Models\Suspension;
use App\Traits\ResolvesExceptionResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

// SCRUM-359: applying a suspension from a resolved report, lifting one early, deciding an appeal,
// and the list of suspensions. Every action authorizes before it validates, so someone without
// the right gets the same answer whatever they send.
class AdminSuspensionController extends Controller
{
    use ResolvesExceptionResponse;

    public function index(Request $request)
    {
        if ($request->user()?->isNotAdmin() ?? true) {
            return Redirect::route('home')->with('message', 'You are not authorized to visit this page.');
        }

        $suspensions = Suspension::query()
            ->with([
                'user', 'createdBy', 'liftedBy', 'appealDecidedBy',
                'report.addedby' => fn ($morph) => $morph->morphWith([Counsellor::class => ['user']]),
                'report.reportable' => fn ($morph) => $morph->morphWith([Session::class => ['for']]),
            ])
            ->orderByRaw('appealed_at is not null and appeal_decided_at is null desc')
            ->orderByRaw('lifted_at is null desc')
            ->latest('id')
            ->paginate(20);

        return Inertia::render('Admin/Suspensions', [
            'suspensions' => AdminSuspensionResource::collection($suspensions)->response()->getData(true),
            'canManage' => $request->user()->isSuperAdmin(),
        ]);
    }

    public function apply(Request $request, int $report)
    {
        try {
            $model = Report::find($report);
            EnsureAdminCanReviewReportAction::new()->execute($request->user(), $model);

            $validated = $request->validate([
                'scope' => ['required', Rule::in(SuspensionScopeEnum::values())],
                'duration' => ['required', Rule::in(['7', '30', '90', 'until_lifted'])],
                'public_reason' => ['required', 'string', 'max:2000'],
                'internal_note' => ['nullable', 'string', 'max:2000'],
            ]);

            ApplySuspensionAction::new()->execute(
                $request->user(),
                $model,
                SuspensionScopeEnum::from($validated['scope']),
                $validated['duration'] == 'until_lifted' ? null : (int) $validated['duration'],
                $validated['public_reason'],
                $validated['internal_note'] ?? null,
            );

            return Redirect::back()->with('message', 'Suspension applied.');
        } catch (Throwable $th) {
            return $this->failed($th);
        }
    }

    public function lift(Request $request, int $suspension)
    {
        try {
            $model = Suspension::with('report')->find($suspension);
            EnsureCanManageSuspensionAction::new()->execute($request->user(), $model);

            $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

            LiftSuspensionAction::new()->execute($request->user(), $model, $validated['reason']);

            return Redirect::back()->with('message', 'Suspension lifted.');
        } catch (Throwable $th) {
            return $this->failed($th);
        }
    }

    public function decideAppeal(Request $request, int $suspension)
    {
        try {
            $model = Suspension::with('report')->find($suspension);
            EnsureCanManageSuspensionAction::new()->execute($request->user(), $model);

            $validated = $request->validate([
                'decision' => ['required', Rule::in(SuspensionAppealDecisionEnum::values())],
                'note' => ['required', 'string', 'max:2000'],
            ]);

            DecideSuspensionAppealAction::new()->execute($request->user(), $model, SuspensionAppealDecisionEnum::from($validated['decision']), $validated['note']);

            return Redirect::back()->with('message', 'Appeal decided.');
        } catch (Throwable $th) {
            return $this->failed($th);
        }
    }

    private function failed(Throwable $th)
    {
        if ($th instanceof ValidationException) {
            throw $th;
        }

        return Redirect::back()->withErrors(['alert' => $this->messageFor($th, $this->statusFor($th))]);
    }
}
