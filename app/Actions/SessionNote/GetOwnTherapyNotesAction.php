<?php

namespace App\Actions\SessionNote;

use App\Actions\Action;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Message;
use App\Models\MessageNote;
use App\Models\Session;
use App\Models\SessionNote;
use App\Models\Therapy;
use App\Support\PdfTextWeight;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

// SCRUM-438: the notes a counsellor wrote on one therapy (1:1 or group) -- on its sessions and on messages in them --
// for the PDF of their notes. Only their own (counsellor_id, as GetOwnSessionNotesAction: a co-counsellor's never
// come with them); deleted notes left out. Grouped by session, oldest first; a message note carries when the message
// was sent, never its text (the client's words aren't the counsellor's notes -- the user's decision). Notes on
// discussion messages aren't therapy session notes and stay out. Capped.
class GetOwnTherapyNotesAction extends Action
{
    /**
     * @return Collection<int, array{session: string, at: ?string, notes: list<array{kind: string, at: string, content: string}>}>
     */
    public function execute(Counsellor $counsellor, Therapy|GroupTherapy $therapy): Collection
    {
        $max = (int) config('limits.notes_export_max_notes');
        $sessionIds = Session::query()->withTrashed()
            ->where('for_type', $therapy->getMorphClass())->where('for_id', $therapy->id)->select('id');

        $sessionNotes = SessionNote::query()->where('counsellor_id', $counsellor->id)->whereIn('session_id', $sessionIds)
            ->orderBy('id')->limit($max + 1)->get();
        $messageNotes = MessageNote::query()->where('counsellor_id', $counsellor->id)
            ->whereIn('message_id', Message::query()->withTrashed()->where('for_type', (new Session)->getMorphClass())->whereIn('for_id', $sessionIds)->select('id'))
            ->with(['message' => fn ($q) => $q->withTrashed()->select(['id', 'for_id', 'for_type', 'created_at'])])
            ->orderBy('id')->limit($max + 1)->get();

        if ($sessionNotes->count() + $messageNotes->count() === 0) {
            throw ValidationException::withMessages(['form' => 'You have no notes on this therapy to export.']);
        }

        $characters = $sessionNotes->concat($messageNotes)->sum(fn ($note) => PdfTextWeight::of($note->content));

        if ($sessionNotes->count() + $messageNotes->count() > $max || $characters > (int) config('limits.notes_export_max_characters')) {
            throw ValidationException::withMessages(['form' => 'That is too much for one PDF.']);
        }

        $sessions = Session::query()->withTrashed()->whereIn('id', $sessionNotes->pluck('session_id')->merge($messageNotes->pluck('message.for_id'))->filter()->unique())
            ->get(['id', 'name', 'start_time'])->keyBy('id');

        $rows = $sessionNotes->map(fn (SessionNote $note) => ['sessionId' => $note->session_id, 'kind' => 'Session note', 'at' => $note->created_at, 'content' => $note->content])
            ->concat($messageNotes->map(fn (MessageNote $note) => [
                'sessionId' => $note->message?->for_id,
                'kind' => 'Note on a message sent '.$note->message?->created_at?->format('j M Y, H:i'),
                'at' => $note->created_at,
                'content' => $note->content,
            ]));

        return $rows->groupBy('sessionId')
            ->map(fn (Collection $notes, $sessionId) => [
                'session' => $sessions->get($sessionId)?->name ?: 'Session',
                'start' => $sessions->get($sessionId)?->start_time,
                'at' => $sessions->get($sessionId)?->start_time?->format('l, j F Y, H:i'),
                'notes' => $notes->sortBy('at')->map(fn ($n) => ['kind' => $n['kind'], 'at' => $n['at']?->format('j M Y, H:i'), 'content' => $n['content']])->values()->all(),
            ])
            ->sortBy('start')->values()->map(fn ($s) => collect($s)->except('start')->all());
    }
}
