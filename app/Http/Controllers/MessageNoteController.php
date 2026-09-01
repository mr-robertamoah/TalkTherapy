<?php

namespace App\Http\Controllers;

use App\DTOs\CreateMessageNoteDTO;
use App\Http\Requests\CreateMessageNoteRequest;
use App\Http\Requests\UpdateMessageNoteRequest;
use App\Http\Resources\MessageNoteResource;
use App\Models\Message;
use App\Models\MessageNote;
use App\Services\MessageNoteService;
use Illuminate\Http\Request;
use Throwable;

class MessageNoteController extends Controller
{
    // counsellor_id is fillable on MessageNote (see the model's own comment), so it is always
    // derived here from the authenticated user's own counsellor profile -- never accepted as
    // client input on any of these endpoints. A caller with no counsellor profile at all simply
    // gets counsellor: null, which every Ensure*Action already rejects.
    public function index(Request $request)
    {
        try {
            $note = MessageNoteService::new()->getOwnMessageNote(
                CreateMessageNoteDTO::new()->fromArray([
                    'user' => $request->user(),
                    'counsellor' => $request->user()->counsellor,
                    'message' => Message::find($request->route('messageId')),
                ])
            );

            // Explicitly wrapped, matching store/update/destroy's own ['note' => ...] shape --
            // never returning a bare resource/collection here (see decision-log.md, SCRUM-198,
            // for why JsonResource::$wrap's process-wide mutable state made that a real,
            // browser-only-reproducible bug for the analogous SessionNote endpoint).
            return response()->json(['note' => $note ? new MessageNoteResource($note) : null]);
        } catch (Throwable $th) {
            return $this->returnFailure($request, $th);
        }
    }

    public function store(CreateMessageNoteRequest $request)
    {
        try {
            $note = MessageNoteService::new()->createMessageNote(
                CreateMessageNoteDTO::new()->fromArray([
                    'user' => $request->user(),
                    'counsellor' => $request->user()->counsellor,
                    'message' => Message::find($request->route('messageId')),
                    'content' => $request->content,
                ])
            );

            return $this->returnSuccess($request, $note);
        } catch (Throwable $th) {
            return $this->returnFailure($request, $th);
        }
    }

    public function update(UpdateMessageNoteRequest $request)
    {
        try {
            $note = MessageNoteService::new()->updateMessageNote(
                CreateMessageNoteDTO::new()->fromArray([
                    'user' => $request->user(),
                    'counsellor' => $request->user()->counsellor,
                    'messageNote' => MessageNote::find($request->route('noteId')),
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
            $note = MessageNoteService::new()->deleteMessageNote(
                CreateMessageNoteDTO::new()->fromArray([
                    'user' => $request->user(),
                    'counsellor' => $request->user()->counsellor,
                    'messageNote' => MessageNote::find($request->route('noteId')),
                ])
            );

            return $this->returnSuccess($request, $note);
        } catch (Throwable $th) {
            return $this->returnFailure($request, $th);
        }
    }

    private function returnSuccess(Request $request, MessageNote $note)
    {
        return response()->json(['note' => new MessageNoteResource($note)]);
    }

    private function returnFailure(Request $request, Throwable $th)
    {
        $status = $this->statusFor($th);
        $message = $this->messageFor($th, $status);

        return response()->json(['message' => $message], $status);
    }
}
