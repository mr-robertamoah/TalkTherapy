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
    // SCRUM-380: every answer here is JSON, whoever asks (api.message.notes.* only, called by axios
    // from MessageBadge) -- deliberately not the shared successResponse(), which would redirect a
    // caller whose Accept prefers HTML.
    //
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
            return $this->failureResponse($request, $th);
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

            return response()->json(['note' => new MessageNoteResource($note)]);
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
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

            return response()->json(['note' => new MessageNoteResource($note)]);
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
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

            return response()->json(['note' => new MessageNoteResource($note)]);
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }
}
