# SCRUM-20: Live chat — dedicated pages, typing indicators, rate limiting & reconnect handling

Moves live chat out of two places it previously lived (a tab inside `DiscussionModal.vue`, and an
embedded section inside `UnifiedTherapy.vue`) into dedicated Inertia pages/routes, and hardens the
result with typing indicators, per-user rate limiting, and reconnect-status feedback. This document
covers the full, final scope across all five milestones.

## What was built

### M1 — Group therapy channel-casing fix

Landed separately as SCRUM-58 (PR #20, commit `16c05c3`) ahead of the rest of this ticket's work:
`GroupTherapy` real-time events were being broadcast/joined on mismatched channel-name casing,
silently breaking live updates for group therapy sessions. Fixed so the channel name is
consistent between the broadcasting side and the `Echo.join(...)` call.

### M2 — Discussion chat

- New route `GET /discussions/{discussionId}/chat` (`discussions.chat`), inside the `auth`
  middleware group. Handled by `DiscussionController::showChat`, which authorizes the viewer
  (admin, or a counsellor who is the discussion's `addedby` or one of its assigned counsellors —
  the same check `MessageService::getDiscussionMessages` already used) and renders
  `Discussion/Chat` with a `DiscussionResource`.
- New page `resources/js/Pages/Discussion/Chat.vue`: the discussion's message history, composer,
  attachments (camera/microphone/file), and its own independent presence-channel join
  (`Echo.join('discussions.{id}')`) for live `.message.created` events — separate from
  `DiscussionModal.vue`'s own join, matching this codebase's existing pattern of two
  independently-mounted components each joining the same channel.
- `DiscussionModal.vue`: removed the `chat` tab entirely (options are now `details`,
  `counsellors`, `sessions`) along with everything that was chat-tab-exclusive (message
  composer/attachments, `discussionMessages`, the `.message.created` listener). The modal's
  `details` tab now has a "go to discussion chat" button that navigates to the new page. The
  presence join, online-participant tracking, and the `.discussion.removecounsellor` kick-out
  safety behavior are untouched and still run regardless of which tab is open.

### M3 — Therapy/group therapy session chat

- New routes (inside the `auth` group — `therapies.get`/`group.therapies.get` themselves stay
  outside it since therapy pages can be public, but chat requires auth):
  `GET /therapies/{therapyId}/chat` (`therapies.chat`) and
  `GET /group-therapies/{groupTherapyId}/chat` (`group.therapies.chat`), handled by
  `TherapyController::chat` / `GroupTherapyController::chat`, mirroring the existing
  `getTherapy`/`getGroupTherapy` authorization (`EnsureUserHasAccessToTherapyAction`) and
  rendering `Therapy/Chat` / `GroupTherapy/Chat`.
- New pages: `resources/js/Pages/TherapyChat.vue` (shared implementation, re-invokes
  `useTherapyState` independently per this codebase's established per-page pattern), with thin
  wrappers `resources/js/Pages/Therapy/Chat.vue` and `resources/js/Pages/GroupTherapy/Chat.vue`
  mirroring `Therapy/Index.vue`/`GroupTherapy/Index.vue`'s existing convention.
- `TherapyChat.vue` hosts a single `TherapyComponent` instance whose props toggle based on
  whether there's an active session: `show-sessions="false"` + `can-start`/`can-end`/`can-abandon`
  when a session is active (the old "message box" modal's behavior), or the defaults (full
  session/topic browsing + chat) when there isn't (the old always-inline behavior) — replicating
  the previous conditional split, just relocated to its own page/route instead of a modal vs. an
  inline slot.
- `UnifiedTherapy.vue`: the inline `#therapy-component` slot (previously always rendering
  `TherapyComponent`) now renders a "go to chat" button instead (the slot itself had to stay,
  since `BaseTherapyLayout.vue` feeds it into `TherapyInformation.vue`'s "chat history" tab). The
  "show message box" button and the entire "Therapy Session Modal" were removed in favor of
  navigating to the new chat route. `clickedStartSession`/`clickedEndSession`/
  `clickedAbandonSession` stay in `UnifiedTherapy.vue` (still used by the "have session"
  MiniModal's own buttons) and are duplicated — not extracted to a shared composable, per the
  ticket's scope — into `TherapyChat.vue` for the same actions there.

### M4 — Typing indicators (individual therapy only)

- `TherapyComponent.vue` whispers a `typing` event on the session's private channel
  (`Echo.private('sessions.{id}').whisper('typing', { userId })`) while the current user has
  content in the composer, throttled to roughly once every 2s (leading + trailing) so it isn't
  sent on every keystroke.
- The other party's `TherapyComponent` instance listens for that whisper
  (`channel.listenForWhisper('typing', ...)`), ignoring its own echoed-back whispers by comparing
  `userId`, and shows a "typing…" line above the composer while it's active.
- The indicator clears in two ways: immediately when a `.message.created` event arrives from the
  other party (they've now sent, so they're no longer "typing"), or automatically after 5s with no
  further `typing` whisper (e.g. the other party stopped typing without sending, or left the
  page).
- Scoped to individual therapy only (`props.therapyType === 'individual'`) — group therapy and
  discussion chat are explicitly out of scope here, tracked separately under SCRUM-68.

### M5 — Rate limiting, reconnect-status UI, defensive guards

- **Rate limiting**: a new `messages` named rate limiter (`RouteServiceProvider::boot`), 30
  requests/minute per authenticated user (falling back to IP for guests), applied via
  `->middleware('throttle:messages')` to all four message mutation routes: `api.messages.create`,
  `api.messages.update`, `api.messages.delete`, `api.messages.delete.me`. Exceeding the limit
  returns Laravel's standard 429 response.
- **Reconnect-status UI**: new composable `resources/js/Composables/useConnectionStatus.js` wraps
  Echo/Reverb's underlying pusher-js connection events (`unavailable`/`disconnected`/`connected`)
  and reports drops/recoveries via caller-supplied callbacks — it does not implement reconnection
  logic itself (pusher-js already retries automatically), only surfaces the state change.
  `TherapyComponent.vue` wires this up to show a "Connection lost. Trying to reconnect…" alert on
  drop and a "Connection restored." alert on recovery.
- **Frontend rate-limit handling**: `MessageBadge.vue`'s create/update/delete/delete-for-me error
  handlers detect a `429` response and show a "You have made too many requests within a short
  period. Try again shortly." alert instead of the generic failure message. This was extracted
  during this fix-up pass into a single shared `handleRateLimitError(err)` helper (previously
  copy-pasted identically across all four handlers).
- **Defensive guards**: `TherapyComponent.vue`'s `replaceOldMessage`/`replaceFirstMessage` guard
  against `itemsRef` being `undefined` (a pre-existing latent null-reference risk once
  `selectedTopicSession` — a session-shaped cross-filter chip — doesn't correspond to an actual
  entry in the per-topic message cache).
- **Backend response-status fix** (found by QA during review of this ticket):
  `MessageController::returnFailure` previously re-threw a bare `Exception` for JSON requests on
  any failure, which discarded the original throwable's HTTP-meaningful `getCode()` — since
  `bootstrap/app.php`'s `withExceptions()` is empty, Laravel's default handler rendered any
  non-HTTP-exception `Throwable` as a generic 500. That meant a correctly-422'd domain rejection
  (e.g. `MessageException` from `EnsureCanSendMessageToForAction` — "you are not allowed to create
  a message for this session") surfaced to the client as a 500 instead of a 422. Fixed to return a
  JSON response using the throwable's own code (falling back to 500 for anything outside the
  400–599 range). Covered by a new feature test,
  `tests/Feature/MessageControllerTest.php`, asserting the real HTTP status a non-participant's
  send is rejected with. The same `returnFailure`/`throw new Exception($message)` pattern exists,
  unfixed, in `TherapyController`, `GroupTherapyController`, and `DiscussionController` — out of
  scope for this ticket, tracked as a follow-up.

## Bugs found and fixed along the way (M3)

While seeding deterministic "active session" data to test M3, two pre-existing, unrelated
bugs surfaced that would otherwise make the "active session" branch of the new chat pages
essentially unreachable in real usage:

1. **`Session::scopeWhereIsNotUserWhoConfirmedHeld`** (`app/Models/Session.php`) combined a
   wrong-case `Status` column with independent `where`/`orWhere` clauses. Since
   `updatedby_type`/`updatedby_id` are null until a session has been through at least one status
   change — true for every session once it reaches a fully-confirmed `IN_SESSION` state,
   per `ChangeSessionStatusAction` — the old logic excluded it from `getActiveSession()` results
   entirely. Fixed to correctly only exclude a session when it's `HELD_CONFIRMATION` *and* the
   given model is the one who set it there.
2. **`GroupTherapy` had no `scopeWhereIsParticipant`** (unlike `Therapy`). Once any
   `GroupTherapy`-type row exists in `sessions.for_type` at all,
   `Session::scopeWhereIsParticipant()`'s `whereHasMorph('for', '*', ...)` evaluates its closure
   against every morph type present in the table; without a matching scope, Eloquent's
   `where{Column}` magic-method fallback silently turned it into `where('is_participant', ...)` —
   a nonexistent column — throwing a SQL error for *every* `Session` query site-wide as soon as
   one group therapy session existed. Fixed by adding the missing scope, mirroring `Therapy`'s.

Regression tests for both: `tests/Unit/SessionActiveSessionTest.php`.

## Files changed

- `app/Http/Controllers/DiscussionController.php` — `showChat`
- `app/Http/Controllers/TherapyController.php` — `chat`
- `app/Http/Controllers/GroupTherapyController.php` — `chat`
- `app/Http/Controllers/MessageController.php` — `returnFailure` now returns the real HTTP status
  for JSON requests instead of always surfacing as a 500
- `app/Models/Session.php` — `scopeWhereIsNotUserWhoConfirmedHeld` fix
- `app/Models/GroupTherapy.php` — added `scopeWhereIsParticipant`
- `app/Providers/RouteServiceProvider.php` — `messages` rate limiter (30/min per user)
- `routes/web.php` — `discussions.chat`, `therapies.chat`, `group.therapies.chat`
- `routes/api.php` — `throttle:messages` added to the four message mutation routes
- `resources/js/Pages/Discussion/Chat.vue` (new)
- `resources/js/Pages/TherapyChat.vue`, `resources/js/Pages/Therapy/Chat.vue`,
  `resources/js/Pages/GroupTherapy/Chat.vue` (new)
- `resources/js/Composables/useConnectionStatus.js` (new)
- `resources/js/Components/TherapyComponent.vue` — typing indicators, connection-status wiring,
  `replaceOldMessage`/`replaceFirstMessage` null guards
- `resources/js/Components/DiscussionModal.vue` — chat tab removed
- `resources/js/Components/MessageBadge.vue` — 429 handling, extracted into a shared
  `handleRateLimitError` helper
- `resources/js/Pages/UnifiedTherapy.vue` — inline component/modal removed, navigates to chat
  pages instead
- `database/seeders/DatabaseSeeder.php` — `createChatDemoData`, with null guards on its seeded-
  account lookups
- `tests/Feature/ChatPageTest.php`, `tests/Unit/SessionActiveSessionTest.php`,
  `tests/Feature/MessageRateLimitTest.php`, `tests/Feature/MessageControllerTest.php` (new)

## How to try it

1. `docker compose exec php php artisan migrate:fresh --seed` (see "Test data" below).
2. Log in as one of the seeded accounts (password `password` for all demo accounts).
3. **Discussion chat**: open the "Chat Demo Discussion" discussion (via its therapy's discussions
   list, or directly at `/discussions/{discussionId}/chat` — get its id from the therapy page or
   tinker, see below) as `sarah_johnson` or `michael_chen`. You'll see existing message history
   and can send new messages (discussion is `IN_SESSION`).
4. **Individual therapy chat**: visit `/therapies/{id}/chat` for "Chat Demo Individual Therapy" as
   `maria_garcia` (the client) or `sarah_johnson` (the counsellor) — since it has a live
   `IN_SESSION` session, you land directly in "active session" chat mode with start/end/abandon
   session actions available per the existing gating.
5. **Group therapy chat**: visit `/group-therapies/{id}/chat` for "Chat Demo Group Therapy" the
   same way — also has a live `IN_SESSION` session.
6. For the general/no-active-session browsing experience, visit the chat route for any other
   seeded therapy/group therapy (session/topic browsing + chat, gated by
   `TherapyComponent`'s existing rules).
7. **Typing indicators**: open "Chat Demo Individual Therapy"'s chat page as both `maria_garcia`
   and `sarah_johnson` at once (e.g. two browser profiles/incognito windows, one logged in as
   each). Start typing in the composer as one party — within ~2s the other party's chat should
   show "typing…" just above their composer. Stop typing without sending: it clears after ~5s of
   inactivity. Alternatively, send the message: it clears immediately once the other party's
   `.message.created` event arrives.
8. **Rate limiting**: while logged in as any user, send more than 30 messages within a minute
   (e.g. via the composer, or by scripting `POST /api/messages` with a valid payload) in the same
   chat. The 31st request within that minute returns HTTP 429, and the composer surfaces "You have
   made too many requests within a short period. Try again shortly." instead of the generic
   send-failure message.
9. **Reconnect-status alert**: this is hard to trigger manually without interrupting the
   websocket connection — the honest way to observe it is `docker compose stop reverb`, then
   `docker compose start reverb` a few seconds later, while a chat page is open. Stopping it should
   surface a "Connection lost. Trying to reconnect…" alert; starting it back up (pusher-js's
   automatic retry reconnects on its own) should surface "Connection restored." shortly after.

## Test data

All of the below is created deterministically by `DatabaseSeeder.php` (via
`createChatDemoData()`), independent of the pre-existing randomized therapies/discussions — so
IDs will vary per reseed, but the **names are stable** and can be looked up directly. As of this
fix-up pass, each lookup in `createChatDemoData()` is guarded with a `throw_if` so a future
rename/reorder of the earlier seed steps fails loudly instead of with a cryptic null-method-call
error.

| Record | Name | Status | Participants |
|---|---|---|---|
| Individual therapy | `Chat Demo Individual Therapy` | `IN_SESSION` (with a live `Chat Demo Live Session`) | client: `maria_garcia`; counsellor: `sarah_johnson` |
| Group therapy | `Chat Demo Group Therapy` | `IN_SESSION` (with a live `Chat Demo Group Live Session`) | participant: `maria_garcia`; counsellor: `sarah_johnson` |
| Discussion | `Chat Demo Discussion` | `IN_SESSION`, with ~10-25 existing messages | `sarah_johnson` (addedby) + `michael_chen` (added counsellor) |

All demo accounts log in with password `password` (see `documentation/seeded-data.md` for the
full roster). Look up the actual IDs for the routes above with:

```bash
docker exec talktherapy-php php artisan tinker --execute="
echo 'therapy: '.App\Models\Therapy::where('name', 'Chat Demo Individual Therapy')->first()->id.PHP_EOL;
echo 'group therapy: '.App\Models\GroupTherapy::where('name', 'Chat Demo Group Therapy')->first()->id.PHP_EOL;
echo 'discussion: '.App\Models\Discussion::where('name', 'Chat Demo Discussion')->first()->id.PHP_EOL;
"
```

## Testing performed

- `docker compose exec vite npm run build` — compiles cleanly.
- `docker compose exec php php artisan test` — full suite passes: 128 tests (126 passed, 2 risky
  — pre-existing empty placeholder tests in `tests/Unit/MessageServiceTest.php`'s "delete message"
  describe blocks, unrelated to this ticket), including:
  - `tests/Feature/ChatPageTest.php` (authenticated participant reaches the right Inertia
    component with the right props; non-participant is redirected/403'd; unauthenticated is
    redirected to login, for all three new routes),
  - `tests/Unit/SessionActiveSessionTest.php` (regression coverage for the two bug fixes above),
  - `tests/Feature/MessageRateLimitTest.php` (30/minute throttling on all four message mutation
    routes, and that the limit is tracked per-user rather than globally),
  - `tests/Feature/MessageControllerTest.php` (a non-participant's send is rejected with an actual
    422 HTTP status, not a 500 — the QA-reported gap this fix-up pass closed).
- `docker compose exec php ./vendor/bin/pint` — clean on all touched PHP files.
- No JS test framework exists in this repo (no vitest/jest) — frontend correctness (typing
  indicators, reconnect UI, rate-limit alert handling) beyond the build succeeding was verified by
  manual code reading/reasoning, not automated frontend tests.
