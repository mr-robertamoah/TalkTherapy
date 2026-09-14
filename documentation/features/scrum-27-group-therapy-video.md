# TT-3.2: Group Therapy Video (SCRUM-27) — v1

Builds group video on top of the base 1:1 infrastructure documented in
`documentation/features/scrum-26-video-calling.md` (dual-provider `VideoProviderInterface`,
`VideoSession`/`VideoSessionParticipant` model, the `useVideoSession.js` frontend composable) —
read that doc first for the shared architecture; this one covers only what's specific to
GroupTherapy.

**This is v1, not the final shape of group video.** Two follow-ups are deliberately deferred and
tracked separately, unstarted as of this closeout:
- **SCRUM-313** — group-scoped guardian video-consent (a minor creator is currently hard-blocked
  outright, not offered a consent flow, since `VideoConsent` has no relation to `GroupTherapy` yet).
- **SCRUM-314** — full-membership group video (every ordinary member gets video access, with a
  real numeric participant cap and per-member anonymity/payment/minor enforcement at scale). v1
  deliberately narrows this away entirely — see the scoping decision below.

## v1 scope (product decision)

A GroupTherapy video call is a **counsellor-team room, with the group's own creator optionally
joining** — not full-membership video. An ordinary group member never has video access at all in
v1. This was the user's own scope-narrowing redirect during `/start-feature` scoping (mirrors the
epic's original "cap N concurrent participants" framing being replaced with "counsellors + optional
creator" instead) — see `documentation/decision-log.md`'s 2026-09-14 "SCRUM-27 (TT-3.2): scoping
decisions and epic split" entry for the full reasoning trail, including the three research
corrections project-manager found (creator-detection, minor-detection, and anonymity-masking all
already generalized to GroupTherapy with zero new code) and the two corrections architect added
(the participant cap needing to be type-aware, and the authorization gap in the originally-proposed
design).

## What was built

- **Authorization + participant cap** (TT-3.2a, SCRUM-308): `EnsureVideoIsAvailableForSessionAction`
  gates GroupTherapy video with a strict allow-list — any active counsellor, or the creator if not a
  minor — layered on top of the existing, broader `Session::isNotParticipant()`/`GroupTherapy::isParticipant()`
  check (which returns true for any ordinary pivot member too; the strict allow-list is what
  actually excludes them from video specifically). `DailyVideoProvider`'s room cap is now type-aware
  (`ConstantsEnum::therapyVideoMaxParticipants` = 2 vs. `groupTherapyVideoMaxParticipants` = 10)
  rather than a single hardcoded flat value. Chime has no equivalent provider-level cap parameter at
  all — deliberately left uncapped there, since v1's own allow-list already bounds who can ever
  reach `createParticipantCredentials()` for a group session (app-level authorization, not a
  provider-level room setting, does the real limiting).
- **Counsellor-only call termination** (TT-3.2d, SCRUM-311, bundled into TT-3.2a's own PR after a
  security-review finding): only an active counsellor may end a GroupTherapy call for everyone — an
  ordinary member (correctly denied JOIN, but still a legitimate `Session` participant for
  `EndVideoSessionAction`'s own broader check) could otherwise still terminate the counsellor team's
  call unilaterally.
- **In-call participant removal** (TT-3.2b/c, SCRUM-309/310): an active counsellor can eject one
  specific participant — another counsellor, or the creator — from an in-progress call without
  ending the room for everyone else, via a new `POST /sessions/{sessionId}/video/participants/{userId}/remove`
  route and `RemoveParticipantFromVideoSessionAction`. Any counsellor may remove any other
  participant (including another counsellor — moderation is a team-wide capability, not
  host-only, matching this codebase's existing "every active counsellor is an equal peer"
  precedent) but never themselves (self-removal uses the existing leave flow instead).
  - **Provider implementations, no new persisted column needed**: Daily's own REST `POST
    /rooms/{room}/eject` accepts `user_ids` directly (the same id already sent at token-mint time);
    Chime has no "eject by external id" call, so `ChimeVideoProvider` looks the live attendee up via
    `ListAttendees` (matching its own `ExternalUserId`, set to our user id at `CreateAttendee` time)
    before calling `DeleteAttendee`.
  - **Security fix during review**: the removal target was initially resolved via a global
    `User::find()` before authorization, letting a caller distinguish "this id doesn't exist" from
    "this id exists but isn't in this call" — a system-wide user-id-existence oracle. Fixed to
    resolve the target only via the video session's own live participants; both cases are now
    byte-identical to the caller.
  - **Frontend** (TT-3.2c): the removed participant's own browser shows a distinct "You were
    removed from this call" screen — deliberately never the generic "call ended" screen or a
    reconnect/disconnect message (`useVideoSession.js`'s own `status === 'removed'`, driven by a new
    `VideoParticipantRemovedEvent` broadcasting on the existing `sessions.{id}` channel). This
    ticket also discovered and closed a real gap: the frontend's own "join video" display gate
    (`TherapyComponent.vue`'s `computedCanJoinVideo`) had never been wired up for GroupTherapy at
    all — a TT-3.1-era leftover hard-coding it to 1:1 Therapy only, meaning group video was
    unreachable in the UI until this ticket.
  - **Deferred, filed as SCRUM-315**: a provider-side removal failure is only logged
    (`Log::warning`), never surfaced to the acting counsellor — matches `EndVideoSessionAction`'s
    already-accepted best-effort contract, but is a materially different risk for removal
    specifically (a counsellor could believe a disruptive participant is gone when they're still
    live provider-side). Needs its own design pass (retry vs. a distinct failure signal).

## Regression matrix result (TT-3.2e closeout)

- **Full Pest suite**: green (1872+ tests; see this closeout's own PR for the exact count at merge
  time) — no regressions to the 1:1 Therapy video path, the guardian-consent layer, or any other
  suite.
- **Cross-provider (Daily + Chime) GroupTherapy paths, re-run together as one filtered pass**: 78
  tests, all passing —
  `EnsureVideoIsAvailableForSessionActionTest` (authorization: counsellor/creator allowed, ordinary
  member denied, minor creator hard-blocked, counsellor-created group has no "creator client"
  concept at all), `EndVideoSessionActionTest`/`RemoveParticipantFromVideoSessionActionTest`
  (counsellor-only termination/removal, 1:1 behavior unchanged), `DailyVideoProviderTest`/
  `ChimeVideoProviderTest` (type-aware cap, removeParticipant on both adapters, including each
  provider's own no-op edge cases), `VideoSessionControllerTest` (the same matrix at the HTTP route
  level).
- **Playwright QA (golden path)**: a counsellor and the group's own creator both see "join video"
  and can reach it; an ordinary member never sees it. The actual live multi-party connection (join
  succeeding through to a connected call, the remove button appearing on a live tile, the removed
  screen rendering) could **not** be driven in a real browser in this dev environment — no real
  Daily/Chime credentials are configured (`.env.docker`), the same pre-existing limitation the base
  1:1 feature has always had. Tracked as its own standing reminder, **SCRUM-317**, covering both
  providers and the full 1:1 + group matrix, to be done once sandbox credentials are available.

## Known, accepted limitations

- **No live provider credentials in this dev environment** — same as the base 1:1 feature (see
  `scrum-26-video-calling.md`'s own section on this). See SCRUM-317.
- **A minor creator gets a plain error, not a consent flow** — until SCRUM-313 lands, clicking
  "join video" as a minor creator surfaces `EnsureGroupTherapyVideoIsAllowed()`'s own
  `VideoException` message through the panel's generic error state, not a dedicated banner (unlike
  the 1:1 case, which has a real consent flow and its own `VideoConsentRequiredBanner`).
- **Ordinary members have zero video access** — by design for v1, not a bug; see SCRUM-314 for the
  tracked follow-up.

## How to try it out

### Test data

Seeded by `DatabaseSeeder::createGroupVideoCallDemoData()` — see `documentation/seeded-data.md`'s
"Group video call + participant removal (SCRUM-308/309/310)" section:

| Username | Password | Role |
|---|---|---|
| `group_video_call_demo_counsellor` | `password` | The group's assigned counsellor — sees "join video" and a "remove" control on other participants' tiles once in the call |
| `group_video_call_demo_creator` | `password` | The group's own (adult) creator — sees "join video" too |
| `group_video_call_demo_member` | `password` | An ordinary member — never sees "join video" at all |
| `group_video_call_demo_minor_creator` | `password` | Creator of a SEPARATE group ("Group Video Call Demo (Minor Creator)"), created while a minor — "join video" is still visible (deliberately not pre-checked, see above), but clicking it is cleanly blocked with a 422 |

"Group Video Call Demo" already has an immediately in-progress, online session.

### Steps

1. Log in as `group_video_call_demo_counsellor`, open "Group Video Call Demo" → chat page →
   confirm "join video" is visible.
2. In a second browser/session, log in as `group_video_call_demo_creator` and join the same call.
3. As the counsellor, confirm a "remove" button appears on the creator's tile (not on the
   counsellor's own local tile); clicking it should eject the creator, whose own screen should show
   the distinct "You were removed from this call" message.
4. Log in as `group_video_call_demo_member` and confirm no "join video" button appears at all.
5. Log in as `group_video_call_demo_minor_creator`, open "Group Video Call Demo (Minor Creator)" →
   confirm "join video" IS visible, but clicking it returns a clean 422 ("Video is not yet available
   to a minor client for group therapy."), never a consent banner (no group-scoped consent flow
   exists yet — see SCRUM-313).

### What a successful result looks like

- Same provider-agnostic guarantees as the base 1:1 flow.
- An ordinary member never reaches video at all — neither the join button nor the route itself
  (server-side 422 either way).
- A minor creator sees the join button (by design) but is cleanly blocked on click, with an
  authorization failure visibly distinct from the counsellor/creator's provider-unavailable failure
  (422 vs. 502, different messages).
- Being removed from a call is visibly, unambiguously distinct from the call simply ending or a
  network disconnect.

Same known limitation as the base 1:1 flow (no real Daily/Chime credentials in this dev
environment) applies here too — join succeeds up through room-creation/credential-minting, but a
live media connection can't be manually driven end-to-end without populating real credentials
first (SCRUM-317).
