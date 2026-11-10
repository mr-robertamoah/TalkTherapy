# SCRUM-71: Enforce anonymity masking in message history and identity-leaking resources

TalkTherapy has two independent creator-set anonymity flags — `Therapy.anonymous` (individual) and
`GroupTherapy.anonymous` (group-level) — plus a per-member pivot column
`group_therapy_user.anonymous` a group member can set for themselves. Before this ticket, none of
these were enforced when rendering message history, and four list/detail resources leaked a
client's real identity regardless of the flags. `routes/channels.php`'s presence-channel masking
also had a bug (read `$participant->anonymous` instead of `$participant->pivot->anonymous`) and
never checked the group-level flag at all.

## What was built

### `isAnonymousFor(User $sender): bool` on `Therapy`, `GroupTherapy`, `Session`

The single source of truth for whether a given sender's identity should currently be masked,
computed live from the current flag value (not a stored per-message snapshot):

- `Therapy::isAnonymousFor()` — returns the therapy's own `anonymous` flag (individual therapy has
  one client party, so who the sender is doesn't change the answer).
- `GroupTherapy::isAnonymousFor()` — **OR logic**: `true` if the group's own `anonymous` flag is
  set, OR if this specific sender's own `group_therapy_user` pivot row has `anonymous = true`.
- `Session::isAnonymousFor()` — passes through to `$this->for?->isAnonymousFor($sender)`, mirroring
  the existing `Session::isParticipant()` pattern.

Anonymity only ever applies to a client/User sender, never a counsellor.

### `MessageResource` — message history masking

Every return branch (`deleted_at`, `deleted_for`-contains-viewer, `confidential`-and-not-a-party,
and the full payload) now nulls `fromUserId` when: the sender is a User (not a Counsellor), the
message's `for` is a `Session` (Discussion messages are always excluded — see "Why Discussion is
excluded" below), that Session's `isAnonymousFor($sender)` is true, and the current viewer is not
the sender themselves.

**Which field carries the masked state, and why**: only `fromUserId` is affected — it's nulled to
`null` for a masked message, or left as the real id for the sender's own view (needed so they can
still see their own message is theirs) and for everyone once the flag stops applying. No new field
was added. `fromUserId` was audited across the frontend
(`resources/js/Components/MessageBadge.vue` and every other consumer) and is used **only** for
equality checks against the viewer's own id — own-message layout (left/right alignment), the "You"
label, and gating the update/delete actions — never rendered as a name or used to look up one.
Nulling it for a non-owner viewer produces the same downstream branch results as leaving it as the
real (but different-from-viewer) id would have, so there is no layout/functionality regression, and
it closes the actual leak (a bare, correct user id — which, before this ticket, could be resolved to
a real name via the four resources below).

**Why Discussion is excluded**: confirmed via `app/Actions/Message/EnsureCanSendMessageToForAction`
that only a counsellor can ever be the `from` of a Discussion message (client Users are rejected by
`validateForDiscussion()` before the message is ever created), so masking can never apply there.
Rather than skip it silently, this is asserted directly as a regression test.

### Four leaking resources fixed

`PublicTherapyResource`, `TherapyMiniResource`, `GroupTherapyResource`, `GroupTherapyMiniResource`
previously exposed `userId`/`addedby` unconditionally. All four now mask consistently, using
`isAnonymousFor()` and following `TherapyResource`'s existing "unmask for the person themselves"
precedent (`$this->addedby?->is($user) || ! $this->anonymous`):

- `PublicTherapyResource` / `TherapyMiniResource`: bare `userId` field → `null` when anonymous and
  the viewer isn't the owner.
- `GroupTherapyResource`: `addedby` (a full `UserMiniResource`, real name included) → replaced with
  `['id' => ..., 'fullName' => 'anonymous']` when anonymous and the viewer isn't the owner (same
  shape `TherapyResource`'s own `user` field already used).
- `GroupTherapyMiniResource`: both its `userId` and `addedby` fields masked the same way.

Masking only ever applies when `addedby_type == User::class` (a `GroupTherapy` can be created by a
`Counsellor`, who is never masked).

### `routes/channels.php`

- `groupTherapies.{groupTherapyId}`: replaced the buggy `$participant->anonymous` (wrong property
  — should have been `$participant->pivot->anonymous`, and would `null`-pointer for a counsellor
  joining, since they have no `group_therapy_user` row at all) with
  `$therapy->isAnonymousFor($user)`, which also picks up the previously-missing group-level flag
  check for free (that's exactly `isAnonymousFor()`'s OR logic). Explicitly guarded so a
  counsellor's own name is never masked, even when the group is anonymous.
- `therapies.{therapyId}`: switched to `Therapy::isAnonymousFor()` for consistency (behaviorally
  identical to the previous direct `$therapy->anonymous` check, since individual therapy ignores
  the sender).

### N+1 mitigation (`MessageService`)

- `getSessionMessages()`: every message here is scoped through a single, already-loaded `Session`
  instance (`$dto->session`) — its `for` (and, for a `GroupTherapy`, that `GroupTherapy`'s `users`
  pivot) is resolved **once** and the same instance is shared across every row via
  `$message->setRelation('for', $session)`, instead of each `Message` independently re-resolving
  `for`/`for.for`/`users` per row.
- `getTherapyTopicMessages()`: a topic's messages can span several different sessions, so there's
  no single shared instance to reuse the same way — instead eager-loads the nested `for.for`
  (`Session` → `Therapy`) in one batched query via `->with(['for.for', 'from'])`. Topics only ever
  belong to an individual `Therapy` (never a `GroupTherapy`), so there's no `users` pivot to worry
  about there.
- `getDiscussionMessages()`: no change needed — Discussion messages never trigger
  `isAnonymousFor()` at all (see above), and `forType` is read from the plain `for_type` column,
  not the `for` relation.
- Both session-message methods above also now eager-load `from` (`->with([..., 'from'])`), since
  masking needs the sender `User` model for every row, not just counsellor-sent ones as before.

## Files changed

- `app/Models/Therapy.php`, `app/Models/GroupTherapy.php`, `app/Models/Session.php` —
  `isAnonymousFor()`
- `app/Http/Resources/MessageResource.php` — masking logic
- `app/Http/Resources/PublicTherapyResource.php`, `TherapyMiniResource.php`,
  `GroupTherapyResource.php`, `GroupTherapyMiniResource.php` — masking
- `routes/channels.php` — bug fix + consistency
- `app/Services/MessageService.php` — N+1 mitigation
- `database/seeders/DatabaseSeeder.php` — `john_davis` added as a per-member-anonymous participant
  (with a message) in the existing `Chat Demo Group Therapy` fixture
- `documentation/seeded-data.md` — documents the new fixture
- `tests/Unit/AnonymityMaskingTest.php` (new) — `isAnonymousFor()` on all three models
- `tests/Unit/MessageResourceAnonymityTest.php` (new) — `MessageResource` masking across individual
  therapy, group therapy, soft-delete, and the Discussion invariant
- `tests/Feature/BroadcastChannelAnonymityTest.php` (new) — the two channel closures
- `tests/Unit/NullUnsafeResourcesTest.php` — one pre-existing test updated (see "Deviations" below)

## How to try it

1. `docker compose exec php php artisan migrate:fresh --seed`.
2. Log in as `maria_garcia` or `sarah_johnson` (password `password`) and open `Chat Demo Group
   Therapy`'s chat page (see "Test data" below for the id lookup) — there's an existing message
   from `john_davis`, whose sender should render with no resolvable identity (masked).
3. Log in as `john_davis` instead and view the same message — you should see it's recognizably
   your own (own-message layout/edit/delete available), since masking never applies to your own
   view of your own message.
4. To see the raw JSON difference directly:
   ```bash
   docker exec talktherapy-php php artisan tinker --execute="
   \$gt = App\Models\GroupTherapy::where('name', 'Chat Demo Group Therapy')->first();
   \$john = App\Models\User::where('username', 'john_davis')->first();
   \$maria = App\Models\User::where('username', 'maria_garcia')->first();
   \$message = \$gt->sessions->first()->messages()->where('from_id', \$john->id)->first();
   \$req = request(); \$req->setUserResolver(fn() => \$maria);
   echo 'as maria: '.json_encode((new App\Http\Resources\MessageResource(\$message->fresh()))->toArray(\$req)).PHP_EOL;
   \$req2 = request(); \$req2->setUserResolver(fn() => \$john);
   echo 'as john: '.json_encode((new App\Http\Resources\MessageResource(\$message->fresh()))->toArray(\$req2)).PHP_EOL;
   "
   ```
   `fromUserId` should be `null` in the first line and `john_davis`'s real id in the second.

## Test data

`Chat Demo Group Therapy` (see `documentation/seeded-data.md`) is **not** itself anonymous, but
`john_davis` is attached as a member with a per-member `anonymous = true` pivot row, and has an
existing message in the group's live session — this exercises the per-member half of
`GroupTherapy::isAnonymousFor()`'s OR logic independently of the group-level flag. Log in as
`maria_garcia` or `sarah_johnson` (any other participant) to see that message masked, or as
`john_davis` to see it show their own real identity.

## Deviations / things worth a second look

- `tests/Unit/NullUnsafeResourcesTest.php`'s pre-existing "`GroupTherapyMiniResource` and
  `PublicTherapyResource` do not crash when addedby has deleted their account" test relied on
  `GroupTherapyFactory`'s default `anonymous => true`, which now triggers masking and nulls
  `userId` — updated that test to pass `anonymous: false` explicitly, since it's about null-safety
  around a deleted `addedby`, not anonymity (kept the two concerns independent rather than
  entangling this feature's masking into an unrelated regression test).
- `GroupTherapy::isParticipant()` (used as an authorization gate ahead of the channel-masking
  logic, both for real usage and in this ticket's own channel tests) only recognizes the group's
  `addedby` (creator) or an assigned counsellor as a "participant" — a plain attached member (via
  the `group_therapy_user` pivot) who isn't the creator is not currently considered a participant
  for that check. This is pre-existing, out of this ticket's scope, but is worth a second look
  since it means a non-creator anonymous member technically can't join the presence channel today
  regardless of this fix (the seeded `john_davis` fixture works around it the same way the
  existing seeder already does for `maria_garcia` — send messages via the model directly rather
  than via the live channel-join flow).
- `toUserId` (recipient identity) was explicitly out of scope per the ticket and is untouched.
