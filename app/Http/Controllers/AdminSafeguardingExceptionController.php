<?php

namespace App\Http\Controllers;

use App\Actions\Guardianship\FindMinorsWithoutActiveGuardianAction;
use App\Actions\SafeguardingException\EndSafeguardingExceptionAction;
use App\Actions\SafeguardingException\EnsureCanManageSafeguardingExceptionAction;
use App\Actions\SafeguardingException\GrantSafeguardingExceptionAction;
use App\Http\Resources\AdminSafeguardingExceptionResource;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Request as ModelsRequest;
use App\Models\SafeguardingException;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Throwable;

// SCRUM-381: safeguarding exceptions for minors paused for a missing guardian. Every administrator
// sees the audit list and who is paused; only the super administrator grants, renews or ends one
// (the actions check, before validating anything else).
class AdminSafeguardingExceptionController extends Controller
{
    public function index(Request $request)
    {
        if ($request->user()?->isNotAdmin() ?? true) {
            return Redirect::route('home')->with('message', 'You are not authorized to visit this page.');
        }

        $exceptions = SafeguardingException::query()
            ->with(['ward', 'grantedBy', 'endedBy', 'request'])
            ->orderByRaw('case when ended_at is null and ends_at > ? then 0 else 1 end', [now()])
            ->latest('id')
            ->paginate(20);

        return Inertia::render('Admin/SafeguardingExceptions', [
            'exceptions' => AdminSafeguardingExceptionResource::collection($exceptions)->response()->getData(true),
            'pausedMinors' => $this->pausedMinors($request->user()),
            // The page's wording only; each row says whether its buttons show (canManage).
            'canManage' => $request->user()->isSuperAdmin(),
        ]);
    }

    public function grant(Request $request, int $ward)
    {
        try {
            GrantSafeguardingExceptionAction::new()->execute(
                $request->user(),
                User::query()->find($ward),
                is_string($request->input('reason')) ? $request->input('reason') : null,
                is_string($request->input('ends_at')) ? $request->input('ends_at') : null,
            );

            return Redirect::back()->with('message', 'Safeguarding exception saved.');
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }

    public function end(Request $request, int $exception)
    {
        try {
            EndSafeguardingExceptionAction::new()->execute(
                $request->user(),
                SafeguardingException::query()->find($exception),
                is_string($request->input('reason')) ? $request->input('reason') : null,
            );

            return Redirect::back()->with('message', 'Safeguarding exception ended.');
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }

    // Minors paused right now who have an open therapy or group (the only ones an exception can
    // help), with any counsellor's request for one that is waiting. SCRUM-382 review (SCRUM-362's
    // rule for reports): a minor $admin counsels, or asked for an exception for, shows only as a row
    // they are involved in -- no name, no reasons, no therapy -- and keyed by position, not user id.
    private function pausedMinors(User $admin): array
    {
        $found = FindMinorsWithoutActiveGuardianAction::new()->execute();
        $rows = $found[FindMinorsWithoutActiveGuardianAction::NO_DOB]
            ->concat($found[FindMinorsWithoutActiveGuardianAction::KNOWN_MINOR])
            ->filter(fn (array $row) => $row['openTherapies'] || $row['groupMemberships'])
            ->keyBy('id');

        if ($rows->isEmpty()) {
            return [];
        }

        $manage = EnsureCanManageSafeguardingExceptionAction::new();
        $isSuper = $admin->isSuperAdmin();
        $users = User::query()->whereIn('id', $rows->keys())->get();
        $requests = ModelsRequest::query()
            ->pendingSafeguardingException()
            ->with(['for', 'from' => fn ($morph) => $morph->morphWith([Counsellor::class => ['user']])])
            ->latest('id')
            ->get()
            ->groupBy(fn (ModelsRequest $request) => (int) ($request->data['wardId'] ?? 0));

        return $users->sortBy('id')->values()->map(function (User $user, int $index) use ($admin, $isSuper, $manage, $rows, $requests) {
            $wardRequests = $requests[$user->id] ?? collect();

            if ($wardRequests->contains(fn (ModelsRequest $request) => $manage->sentBy($request, $admin)) || $manage->counsels($admin, $user)) {
                return ['key' => "involved-{$index}", 'involved' => true, 'canManage' => false];
            }

            return [
                'key' => "ward-{$user->id}",
                'involved' => false,
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'noDob' => is_null($user->dob),
                'openTherapies' => $rows[$user->id]['openTherapies'],
                'groupMemberships' => $rows[$user->id]['groupMemberships'],
                // Not involved (above), so a super administrator here doesn't counsel the minor.
                'canManage' => $isSuper,
                'requests' => $wardRequests->map(fn (ModelsRequest $request) => [
                    'id' => $request->id,
                    'counsellor' => $request->from?->name ?? 'a deleted counsellor',
                    'therapy' => $request->for?->name,
                    'isGroup' => $request->for instanceof GroupTherapy,
                    'reason' => $request->data['reason'] ?? null,
                    'proposedEndsAt' => $request->data['proposedEndsAt'] ?? null,
                    'createdAt' => $request->created_at,
                ])->values(),
            ];
        })->all();
    }
}
