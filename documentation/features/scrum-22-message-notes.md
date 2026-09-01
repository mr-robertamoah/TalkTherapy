# TT-2.3 (SCRUM-22): Counsellor message notes

A counsellor can attach a private, indefinitely-editable note to one specific chat message — for
flagging or annotating a particular thing a client (or another counsellor, in a discussion) said —
distinct from TT-2.2's session-level notes. Works uniformly across individual therapy sessions,
group therapy sessions, and counsellor-to-counsellor discussions, since chat messages in this app
are a single polymorphic `Message` model across all three contexts.

Split into two sub-tickets: SCRUM-202 (TT-2.3a, data model + authorization + CRUD) and SCRUM-203
(TT-2.3b, resource + UI).

## What was built

- `message_notes` table: `message_id`, `counsellor_id` (both `nullOnDelete`, matching TT-2.2's
  clinical-record-preservation precedent), `content`, soft-deletes, a unique index on
  `(message_id, counsellor_id)` enforcing at most one note per counsellor per message.
- Authorization (`App\Actions\MessageNote\*`, no Policy layer — matches this codebase's
  established convention): a counsellor can only annotate a message if they're the actual
  counsellor for its context (branches on `Message::for` being a `Discussion` — counsellor-only
  participants — or a `Session`, whose own `for` is the `Therapy`/`GroupTherapy` carrying the real
  `isCounsellor()` check). Author-only edit/delete.
- **Deliberately no time-based edit window**, unlike TT-2.2's session notes — a message note can
  be edited or deleted by its author indefinitely. This was presented to the user as an explicit
  fork (the alternative reused TT-2.2's `GuardsPrivateNoteEditWindow` trait, which was originally
  built with this ticket in mind) and the user chose indefinite editability, since a message-level
  annotation is often reviewed well after the session it's attached to has ended, and `Discussion`
  (one of `Message::for`'s two targets) has no `ended_at` concept at all to gate on. See
  `documentation/decision-log.md`.
- Strictly private to the annotating counsellor — never visible to the client, never to a
  co-counsellor on a shared group session or discussion, even one who is themselves authorized to
  annotate the same message.
- UI: an inline "add note" / "view note" affordance under each message, in
  `resources/js/Components/MessageBadge.vue` — the single component both the therapy chat page and
  the discussion chat page render messages through. Visible only to counsellors.

## How to try it out

### Golden path — individual therapy session

1. Log in as `sarah_johnson` / `password`.
2. Visit `/therapies/6/chat` (seeded "Chat Demo Individual Therapy," live session).
3. Send a message (or use an existing one).
4. Under the message, click **add note**, type something, and click **save**.
5. The note appears inline with **edit**/**delete** controls. Reload the page — the note is still
   there (confirms it's read from the server on every message-list fetch, not just kept in local
   component state).

### Cross-counsellor isolation — shared group therapy session

1. Log in as `sarah_johnson`, visit `/group-therapies/5/chat` (seeded "Chat Demo Group Therapy" —
   both Sarah Johnson and Michael Chen are active counsellors on its live session, added
   specifically for this purpose). Add a note to any message.
2. Log out, log in as `michael_chen`, visit the same chat page. The same message shows **add
   note**, not Sarah's note — confirming isolation.

### Discussion chat

1. Log in as `sarah_johnson`, visit the "Chat Demo Discussion" chat page (`IN_SESSION` between
   Sarah Johnson and Michael Chen — see `documentation/seeded-data.md`). The note affordance
   behaves identically there.

### Confirming the client never sees any of this

1. Log in as `maria_garcia` / `password` (the client on the seeded chat-demo fixtures).
2. Visit the therapy or group therapy chat page above — there is no note affordance anywhere
   under any message, and no `/api/messages/*/notes` network request ever fires.

## Test data

No new seed data was needed — TT-2.2's existing seeded fixtures (two-counsellor group session,
discussion between Sarah Johnson and Michael Chen) already cover this feature's isolation
scenarios. See `documentation/seeded-data.md`.

## Follow-ups filed, deliberately out of scope

- **SCRUM-204** — an unrelated, pre-existing bug found during security review:
  `DiscussionService::getDiscussionCounsellors` has no participant/admin check at all, letting any
  authenticated user read any discussion's counsellor roster.
- **SCRUM-205** — an unrelated, pre-existing N+1 found while writing this ticket's own N+1
  regression test: `MessageResource` doesn't eager-load `files`/`replying` in any of the three
  message-list endpoints.
