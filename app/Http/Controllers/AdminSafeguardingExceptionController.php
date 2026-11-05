<?php

namespace App\Http\Controllers;

use App\Actions\Guardianship\FindMinorsWithoutActiveGuardianAction;
use App\Actions\SafeguardingException\EndSafeguardingExceptionAction;
use App\Actions\SafeguardingException\EnsureCanManageSafeguardingExceptionAction;
use App\Actions\SafeguardingException\GrantSafeguardingExceptionAction;
use App\Actions\User\AnonymiseClosedAccountAction;
use App\Http\Resources\AdminSafeguardingExceptionResource;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Request as ModelsRequest;
use App\Models\SafeguardingException;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Throwable;

// SCRUM-381: safeguarding exceptions for minors paused for a missing guardian. Every administrator
// sees the audit list and who is paused; only the super administrator grants, renews or ends one
// (the actions check, before validating anything else).
class AdminSafeguardingExceptionController extends Controller
{
    private const PAUSED_PER_PAGE = 20;

    public function index(Request $request)
    {
        if ($request->user()?->isNotAdmin() ?? true) {
            return Redirect::route('home')->with('message', 'You are not authorized to visit this page.');
        }

        $exceptions = SafeguardingException::query()
            ->with(['ward', 'grantedBy', 'endedBy', 'request'])
            ->orderByRaw('case when ended_at is null and ends_at > ? then 0 else 1 end', [now()])
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        // SCRUM-384: one instance for the whole page (read-only), so "do they counsel this minor"
        // is read once per minor across both lists.
        $manage = EnsureCanManageSafeguardingExceptionAction::new()->remembering();
        $pending = ModelsRequest::query()
            ->pendingSafeguardingException()
            ->with(['for', 'from' => fn ($morph) => $morph->morphWith([Counsellor::class => ['user']])])
            ->latest('id')
            ->get()
            ->groupBy(fn (ModelsRequest $request) => (int) $request->safeguardingWardId());

        $paused = $this->pausedMinors($request, $manage, $pending);

        return Inertia::render('Admin/SafeguardingExceptions', [
            'exceptions' => AdminSafeguardingExceptionResource::collection($exceptions)->response()->getData(true),
            'pausedMinors' => ['data' => $paused->items(), 'meta' => Arr::except($paused->toArray(), 'data')],
            'noLongerNeeded' => $this->noLongerNeeded($request->user(), $manage, $pending),
            // The page's wording only; each row says whether its buttons show (canManage).
            'canManage' => $request->user()->isSuperAdmin(),
            // SCRUM-384: the terms form's limit, from the model rather than a copy in the UI.
            'safeguardingExceptionMaxDays' => SafeguardingException::MAX_DAYS,
        ]);
    }

    public function grant(Request $request, int $ward)
    {
        try {
            GrantSafeguardingExceptionAction::new()->execute(
                $request->user(),
                User::query()->find($ward),
                is_string($request->input('reason')) ? $request->input('reason') : null,
                is_string($request->input('endsAt')) ? $request->input('endsAt') : null,
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
    // SCRUM-384: paged (`pausedPage`, so the exceptions keep `page`); only the page's rows are built.
    private function pausedMinors(Request $request, EnsureCanManageSafeguardingExceptionAction $manage, Collection $pending): LengthAwarePaginator
    {
        $admin = $request->user();
        $found = FindMinorsWithoutActiveGuardianAction::new()->execute();
        $rows = $found[FindMinorsWithoutActiveGuardianAction::NO_DOB]
            ->concat($found[FindMinorsWithoutActiveGuardianAction::KNOWN_MINOR])
            ->filter(fn (array $row) => $row['openTherapies'] || $row['groupMemberships'])
            ->sortBy('id')
            ->values();

        $perPage = self::PAUSED_PER_PAGE;
        $page = LengthAwarePaginator::resolveCurrentPage('pausedPage');
        $offset = ($page - 1) * $perPage;
        $onPage = $rows->slice($offset, $perPage)->values();
        // A row whose account went in the meantime is left out.
        $users = User::query()->whereIn('id', $onPage->pluck('id'))->get()->keyBy('id');
        $isSuper = $admin->isSuperAdmin();

        $items = $onPage->map(function (array $row, int $index) use ($admin, $isSuper, $manage, $users, $pending, $offset) {
            $user = $users->get($row['id']);
            $wardRequests = $pending[$row['id']] ?? collect();

            if (! $user) {
                return null;
            }

            if ($manage->involves($admin, $user) || $wardRequests->contains(fn (ModelsRequest $request) => $manage->sentBy($request, $admin))) {
                return ['key' => 'involved-'.($offset + $index), 'involved' => true, 'canManage' => false];
            }

            return [
                'key' => "ward-{$user->id}",
                'involved' => false,
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'noDob' => is_null($user->dob),
                'openTherapies' => $row['openTherapies'],
                'groupMemberships' => $row['groupMemberships'],
                // Not involved (above), so a super administrator here doesn't counsel the minor.
                'canManage' => $isSuper,
                'requests' => $wardRequests->map(fn (ModelsRequest $request) => $this->requestRow($request))->values(),
            ];
        })->filter()->values()->all();

        return new LengthAwarePaginator($items, $rows->count(), $perPage, $page, [
            'path' => $request->url(),
            'pageName' => 'pausedPage',
            'query' => $request->query(),
        ]);
    }

    // SCRUM-384: counsellors' requests still waiting although the minor is no longer paused -- they
    // have a guardian, an exception is in force, they turned 18, or the account was closed. Nothing
    // answers these on its own (accepting is refused as "no longer needed"), so the super
    // administrator rejects them here. The same masking as "Paused now" for an involved administrator.
    private function noLongerNeeded(User $admin, EnsureCanManageSafeguardingExceptionAction $manage, Collection $pending): array
    {
        $wards = User::withTrashed()->whereIn('id', $pending->keys())->get()->keyBy('id');
        $rows = [];

        foreach ($pending->sortKeys() as $wardId => $requests) {
            $ward = $wards->get($wardId);
            $why = $this->whyNoLongerNeeded($ward);

            if (is_null($why)) {
                continue;
            }

            foreach ($requests as $request) {
                if ($manage->involves($admin, $ward, $request)) {
                    $rows[] = ['key' => 'involved-'.count($rows), 'involved' => true, 'canManage' => false];

                    continue;
                }

                $rows[] = [
                    'key' => "request-{$request->id}",
                    'involved' => false,
                    'client' => $ward && ! $ward->trashed() ? $ward->name : AnonymiseClosedAccountAction::CLOSED_ACCOUNT_NAME,
                    'why' => $why,
                    // Rejecting goes through requests.respond -- also when the minor's row is gone.
                    'canManage' => $manage->allowsClosing($admin, $ward),
                    ...$this->requestRow($request),
                ];
            }
        }

        return $rows;
    }

    // Null while the minor is still paused (their request belongs under "Paused now").
    private function whyNoLongerNeeded(?User $ward): ?string
    {
        if (! $ward || $ward->trashed()) {
            return 'The account was closed.';
        }

        if ($ward->isPausedForMissingGuardian()) {
            return null;
        }

        return match (true) {
            $ward->isAdult() => 'They have turned 18.',
            $ward->hasActiveGuardian() => 'They have a guardian now.',
            $ward->hasActiveSafeguardingException() => 'A safeguarding exception is in force.',
            default => 'They are no longer paused.',
        };
    }

    private function requestRow(ModelsRequest $request): array
    {
        return [
            'id' => $request->id,
            'counsellor' => $request->from?->name ?? 'a deleted counsellor',
            'therapy' => $request->for?->name,
            'isGroup' => $request->for instanceof GroupTherapy,
            'reason' => $request->data['reason'] ?? null,
            'proposedEndsAt' => $request->data['proposedEndsAt'] ?? null,
            'createdAt' => $request->created_at,
        ];
    }
}
