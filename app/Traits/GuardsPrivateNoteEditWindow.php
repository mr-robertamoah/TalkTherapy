<?php

namespace App\Traits;

use App\Models\Session;

// Shared by SessionNote's Ensure*Actions (SCRUM-197/TT-2.2b) and intended for the sibling ticket
// TT-2.3 ("counsellor can annotate a specific chat message with a timestamped note", not yet
// built) to reuse rather than re-derive -- both features are counsellor-authored, timestamped,
// unconditionally-private notes that must stay creatable only while a session is live, and
// editable only during that live window or a short grace period after it ends.
trait GuardsPrivateNoteEditWindow
{
    // Reuses Session::scopeWhereInSession as the single source of truth for "is this session
    // currently live" -- a narrower predicate than Message::acceptsMessage() (which also
    // includes PENDING, flagged there with its own "remove if it does not make sense" TODO) --
    // rather than duplicating a status list here.
    protected function sessionIsLive(?Session $session): bool
    {
        if (! $session) {
            return false;
        }

        return Session::query()->whereInSession()->whereKey($session->id)->exists();
    }

    protected function sessionAcceptsNewNotes(?Session $session): bool
    {
        return $this->sessionIsLive($session);
    }

    // A session that has ended still accepts note edits for a configurable grace period. Once
    // ended_at is set (see ChangeSessionStatusAction), it is checked FIRST and takes permanent
    // priority over the session's current live-looking status -- deliberately, not an oversight:
    // ended_at is set exactly once and never reset, whereas status/updated_at can be freely
    // replayed by the existing /in_session, /end, /fail, /abandon endpoints (none of which are
    // idempotent against an already-terminal session). Deriving this from the live status alone
    // would let a note's own author trivially reopen or indefinitely extend its edit window by
    // re-hitting one of those endpoints -- exactly the guarantee this grace window exists to
    // enforce (security-engineer finding, SCRUM-197).
    protected function sessionAcceptsNoteEdits(?Session $session): bool
    {
        if (! $session) {
            return false;
        }

        if ($session->ended_at) {
            return $session->ended_at
                ->addMinutes(config('session-notes.edit_grace_minutes'))
                ->isFuture();
        }

        return $this->sessionIsLive($session);
    }
}
