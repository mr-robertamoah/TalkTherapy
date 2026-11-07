<?php

namespace App\Http\Controllers;

use App\Actions\Report\EnsureAdminCanReviewReportAction;
use App\Actions\Suspension\ApplySuspensionAction;
use App\Actions\Suspension\DecideSuspensionAppealAction;
use App\Actions\Suspension\EnsureCanManageSuspensionAction;
use App\Actions\Suspension\LiftSuspensionAction;
use App\Enums\SuspensionAppealDecisionEnum;
use App\Enums\SuspensionScopeEnum;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Resources\AdminSuspensionResource;
use App\Models\Counsellor;
use App\Models\Report;
use App\Models\Session;
use App\Models\Suspension;
use App\Support\AdminSearch;
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
    public function index(Request $request)
    {
        if ($request->user()?->isNotAdmin() ?? true) {
            return Redirect::route('home')->with('message', EnsureUserIsAdmin::MESSAGE);
        }

        // SCRUM-390: the suspended person's names and username; one the viewer is involved in (shown
        // only as "involved") never matches.
        $search = AdminSearch::term($request);
        $query = Suspension::query()
            ->when($search, fn ($query, $term) => $query->whereHas('user', fn ($query) => AdminSearch::person($query, $term)))
            ->with([
                'user', 'createdBy', 'liftedBy', 'appealDecidedBy',
                'report.addedby' => fn ($morph) => $morph->morphWith([Counsellor::class => ['user']]),
                'report.reportable' => fn ($morph) => $morph->morphWith([Session::class => ['for']]),
            ]);
        $tooMany = $search ? AdminSearch::withoutInvolved($query, $request->user(), fn (Suspension $suspension, $viewer) => $suspension->involves($viewer)) : false;

        $suspensions = $query
            ->orderByRaw('appealed_at is not null and appeal_decided_at is null desc')
            ->orderByRaw('case when appealed_at is not null and appeal_decided_at is null then appealed_at end')
            ->orderByRaw('lifted_at is null desc')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Admin/Suspensions', [
            'search' => $search ?? '',
            'searchTooMany' => $tooMany,
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
