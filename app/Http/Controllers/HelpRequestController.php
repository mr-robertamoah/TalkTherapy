<?php

namespace App\Http\Controllers;

use App\Actions\Help\AcceptHelpRequestAction;
use App\Actions\Help\CancelHelpRequestAction;
use App\Actions\Help\EndHelpChatAction;
use App\Actions\Help\GetHelpChatAction;
use App\Actions\Help\SendHelpMessageAction;
use App\Actions\Help\SetUrgentHelpAvailabilityAction;
use App\Actions\Help\StartHelpRequestAction;
use App\Actions\Help\WaitingRequestsForCounsellorAction;
use App\Http\Resources\HelpRequestResource;
use App\Models\HelpRequest;
use App\Support\FailureResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Throwable;

// SCRUM-33: the quick help chat. Every route is named help.* -- open to a suspended account too
// (EnsureAccountIsNotSuspended); each action checks who may use it.
class HelpRequestController extends Controller
{
    // The person's live request, if any (the help button resumes it).
    public function current(Request $request)
    {
        $live = HelpRequest::query()->liveFor($request->user())->first();

        return response()->json(['helpRequest' => $live ? new HelpRequestResource($live) : null]);
    }

    public function store(Request $request)
    {
        try {
            $help = StartHelpRequestAction::new()->execute(
                $request->user(),
                $request->boolean('anonymous'),
                is_string($request->input('note')) ? $request->input('note') : null,
                $request->ip(),
            );

            return response()->json(['helpRequest' => new HelpRequestResource($help)]);
        } catch (Throwable $th) {
            return FailureResponse::json($th);
        }
    }

    public function show(Request $request, int $helpRequest)
    {
        try {
            $help = GetHelpChatAction::new()->ensureCanView($request->user(), HelpRequest::query()->find($helpRequest));

            return response()->json(['helpRequest' => new HelpRequestResource($help)]);
        } catch (Throwable $th) {
            return FailureResponse::json($th);
        }
    }

    // The chat page (the person and the counsellor; read-only once it has ended).
    public function page(Request $request, int $helpRequest)
    {
        try {
            $help = GetHelpChatAction::new()->ensureCanView($request->user(), HelpRequest::query()->find($helpRequest));
        } catch (Throwable $th) {
            return redirect()->route('home')->withErrors(['alert' => GetHelpChatAction::NOT_FOUND]);
        }

        if (! $help->isParticipant($request->user())) {
            return redirect()->route('home')->withErrors(['alert' => GetHelpChatAction::NOT_FOUND]);
        }

        return Inertia::render('Help/Chat', [
            'helpRequest' => (new HelpRequestResource($help))->resolve($request),
            'messages' => GetHelpChatAction::new()->messages($request->user(), $help)->values(),
        ]);
    }

    public function messages(Request $request, int $helpRequest)
    {
        try {
            $help = GetHelpChatAction::new()->ensureCanView($request->user(), HelpRequest::query()->find($helpRequest));

            return response()->json(['messages' => GetHelpChatAction::new()->messages($request->user(), $help)->values()]);
        } catch (Throwable $th) {
            return FailureResponse::json($th);
        }
    }

    public function sendMessage(Request $request, int $helpRequest)
    {
        try {
            $help = HelpRequest::query()->find($helpRequest);
            $message = SendHelpMessageAction::new()->execute($request->user(), $help, $request->input('content'));

            return response()->json(['message' => GetHelpChatAction::present($message, $request->user(), $help)]);
        } catch (Throwable $th) {
            return FailureResponse::json($th);
        }
    }

    public function cancel(Request $request, int $helpRequest)
    {
        return $this->respond($request, fn () => CancelHelpRequestAction::new()->execute($request->user(), HelpRequest::query()->find($helpRequest)));
    }

    public function accept(Request $request, int $helpRequest)
    {
        return $this->respond($request, fn () => AcceptHelpRequestAction::new()->execute($request->user(), HelpRequest::query()->find($helpRequest)));
    }

    public function end(Request $request, int $helpRequest)
    {
        return $this->respond($request, fn () => EndHelpChatAction::new()->execute($request->user(), HelpRequest::query()->find($helpRequest)));
    }

    // A counsellor's rings to answer now: requests already waiting that they could take (oldest first)
    // -- for when they switch on, or reload the page while someone waits.
    public function ringing(Request $request)
    {
        return response()->json(['helpRequests' => HelpRequestResource::collection(
            WaitingRequestsForCounsellorAction::new()->execute($request->user())
        )]);
    }

    public function availability(Request $request)
    {
        try {
            $until = SetUrgentHelpAvailabilityAction::new()->execute($request->user(), $request->boolean('available'));

            return response()->json(['availableUntil' => $until]);
        } catch (Throwable $th) {
            return FailureResponse::json($th);
        }
    }

    private function respond(Request $request, callable $action)
    {
        try {
            return response()->json(['helpRequest' => new HelpRequestResource($action())]);
        } catch (Throwable $th) {
            return FailureResponse::json($th);
        }
    }
}
