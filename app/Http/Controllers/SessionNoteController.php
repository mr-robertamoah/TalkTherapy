<?php

namespace App\Http\Controllers;

use App\DTOs\CreateSessionNoteDTO;
use App\Http\Requests\CreateSessionNoteRequest;
use App\Http\Requests\UpdateSessionNoteRequest;
use App\Http\Resources\SessionNoteResource;
use App\Models\Session;
use App\Models\SessionNote;
use App\Services\SessionNoteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Throwable;

class SessionNoteController extends Controller
{
    // counsellor_id is fillable on SessionNote (see the model's own comment), so it is always
    // derived here from the authenticated user's own counsellor profile -- never accepted as
    // client input on any of these endpoints. A caller with no counsellor profile at all simply
    // gets counsellor: null, which every Ensure*Action already rejects.
    public function index(Request $request)
    {
        try {
            $notes = SessionNoteService::new()->getOwnSessionNotes(
                CreateSessionNoteDTO::new()->fromArray([
                    'user' => $request->user(),
                    'counsellor' => $request->user()->counsellor,
                    'session' => Session::find($request->route('sessionId')),
                ])
            );

            return SessionNoteResource::collection($notes);
        } catch (Throwable $th) {
            return $this->returnFailure($request, $th);
        }
    }

    public function store(CreateSessionNoteRequest $request)
    {
        try {
            $note = SessionNoteService::new()->createSessionNote(
                CreateSessionNoteDTO::new()->fromArray([
                    'user' => $request->user(),
                    'counsellor' => $request->user()->counsellor,
                    'session' => Session::find($request->route('sessionId')),
                    'content' => $request->content,
                ])
            );

            return $this->returnSuccess($request, $note);
        } catch (Throwable $th) {
            return $this->returnFailure($request, $th);
        }
    }

    public function update(UpdateSessionNoteRequest $request)
    {
        try {
            $note = SessionNoteService::new()->updateSessionNote(
                CreateSessionNoteDTO::new()->fromArray([
                    'user' => $request->user(),
                    'counsellor' => $request->user()->counsellor,
                    'sessionNote' => SessionNote::find($request->route('noteId')),
                    'content' => $request->content,
                ])
            );

            return $this->returnSuccess($request, $note);
        } catch (Throwable $th) {
            return $this->returnFailure($request, $th);
        }
    }

    public function destroy(Request $request)
    {
        try {
            $note = SessionNoteService::new()->deleteSessionNote(
                CreateSessionNoteDTO::new()->fromArray([
                    'user' => $request->user(),
                    'counsellor' => $request->user()->counsellor,
                    'sessionNote' => SessionNote::find($request->route('noteId')),
                ])
            );

            return $this->returnSuccess($request, $note);
        } catch (Throwable $th) {
            return $this->returnFailure($request, $th);
        }
    }

    private function returnSuccess(Request $request, SessionNote $note)
    {
        $note = new SessionNoteResource($note);

        if ($request->acceptsJson()) {
            return response()->json(['note' => $note]);
        }

        return Redirect::back()->with(['note' => $note]);
    }

    private function returnFailure(Request $request, Throwable $th)
    {
        $status = $this->statusFor($th);
        $message = $this->messageFor($th, $status);

        if ($request->acceptsJson()) {
            return response()->json(['message' => $message], $status);
        }

        return Redirect::back()->withErrors(['alert' => $message]);
    }
}
