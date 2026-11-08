<?php

namespace App\Http\Controllers;

use App\Actions\Help\CloseHelpRequestByAdminAction;
use App\Actions\Help\GetCounsellorsForHelpRequestAction;
use App\Actions\Help\GetHelpQueueAction;
use App\Actions\Help\RevealHelpRequesterAction;
use App\Actions\Help\RingCounsellorForHelpRequestAction;
use App\Actions\Help\RingHelpRequestAgainAction;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\Counsellor;
use App\Models\HelpRequest;
use App\Support\AdminSearch;
use App\Support\FailureResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Throwable;

// SCRUM-33 (TT-4.4b): the administrators' urgent help queue. Administrators coordinate -- ring again,
// ring one counsellor, close -- and never chat or read a chat from here.
class AdminHelpRequestController extends Controller
{
    public function index(Request $request)
    {
        if ($request->user()?->isNotAdmin() ?? true) {
            return Redirect::route('home')->with('message', EnsureUserIsAdmin::MESSAGE);
        }

        $search = AdminSearch::term($request);

        return Inertia::render('Admin/HelpRequests', [
            ...GetHelpQueueAction::new()->execute($request->user(), $search),
            'search' => $search ?? '',
            'expireAfterMinutes' => (int) config('help.expire_after_minutes'),
        ]);
    }

    // The queue again, for the page's live refresh.
    public function queue(Request $request)
    {
        if ($request->user()?->isNotAdmin() ?? true) {
            return response()->json(['message' => EnsureUserIsAdmin::MESSAGE], 403);
        }

        return response()->json(GetHelpQueueAction::new()->execute($request->user(), AdminSearch::term($request)));
    }

    public function ringAgain(Request $request, int $helpRequest)
    {
        try {
            $rung = RingHelpRequestAgainAction::new()->execute($request->user(), HelpRequest::query()->find($helpRequest));

            return response()->json(['rung' => $rung]);
        } catch (Throwable $th) {
            return FailureResponse::json($th);
        }
    }

    public function ringCounsellor(Request $request, int $helpRequest)
    {
        try {
            RingCounsellorForHelpRequestAction::new()->execute(
                $request->user(),
                HelpRequest::query()->find($helpRequest),
                is_numeric($request->input('counsellorId')) ? Counsellor::query()->find((int) $request->input('counsellorId')) : null,
            );

            return response()->json(['status' => true]);
        } catch (Throwable $th) {
            return FailureResponse::json($th);
        }
    }

    public function close(Request $request, int $helpRequest)
    {
        $request->validate(['note' => ['nullable', 'string', 'max:300']]);

        try {
            CloseHelpRequestByAdminAction::new()->execute($request->user(), HelpRequest::query()->find($helpRequest), $request->input('note'));

            return response()->json(['status' => true]);
        } catch (Throwable $th) {
            return FailureResponse::json($th);
        }
    }

    public function reveal(Request $request, int $helpRequest)
    {
        try {
            return response()->json(RevealHelpRequesterAction::new()->execute($request->user(), HelpRequest::query()->find($helpRequest)));
        } catch (Throwable $th) {
            return FailureResponse::json($th);
        }
    }

    public function counsellors(Request $request, int $helpRequest)
    {
        try {
            $search = is_string($request->query('search')) ? mb_substr(trim($request->query('search')), 0, 100) : null;

            return response()->json(['counsellors' => GetCounsellorsForHelpRequestAction::new()->execute(
                $request->user(), HelpRequest::query()->find($helpRequest), $search ?: null,
            )]);
        } catch (Throwable $th) {
            return FailureResponse::json($th);
        }
    }
}
