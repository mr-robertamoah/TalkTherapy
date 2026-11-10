# TT-3.2f: Full-Membership Group Video (SCRUM-314)

Opens group video to **every member** of a GroupTherapy, as a moderated "town hall":
- Members join **receive-only**: they see and hear the call, but can't be seen or heard.
- They can **raise a hand**.
- Any active counsellor can **let them speak** and later **stop** them.

Built on two earlier docs:
- `documentation/features/scrum-26-video-calling.md` covers the base infrastructure: the
  dual-provider `VideoProviderInterface`, the `VideoSession`/`VideoSessionParticipant` model and
  the `useVideoSession.js` composable.
- `documentation/features/scrum-27-group-therapy-video.md` covers group video v1: a counsellor-team
  room with the creator optionally joining, counsellor-only end, and participant removal.

Read those first. This doc covers only what TT-3.2f added.

**Still pending, the real fix for minors: SCRUM-313**, group-scoped guardian video consent. Until it
ships, a minor member can watch but can **never** be granted speaking permission. That is an interim
hard-block (`EnsureMemberMayBeGrantedSpeakingAction`), not a consent flow. SCRUM-329 replaces that
one action once SCRUM-313 lands.

## Scope (product decisions)

All of these were decided by the user during `/start-feature` scoping and the follow-ups. The full
trail is in `documentation/decision-log.md`, from the "SCRUM-314 (TT-3.2f): full-membership group
video scoped and approved" entry through to SCRUM-327.

- **Receive-only by default, enforced by the provider.**
  - Members are enforced through a Daily meeting-token `permissions.canSend: false` or Chime attendee
    `Capabilities: Receive`. It never depends on trusting the browser.
  - Counsellors and the group's creator join able to speak.
- **Speaking permission is granted and revoked live** at the provider, with no reconnect. A
  research spike (SCRUM-320) confirmed both providers support this server-side: Daily's
  `/rooms/{room}/update-permissions` and Chime's `UpdateAttendeeCapabilities`.
- **Peer moderation.** Any active counsellor can grant, and any active counsellor can revoke anyone's
  grant, including another counsellor's. A counsellor can grant without a raised hand (backend), but
  the UI grants from the raised-hand queue.
- **Minors** watch only (see SCRUM-313 above).
- **Anonymity.**
  - **Anonymous members** (the group is anonymous, or they joined anonymously) are granted **audio
    only**, and their camera is never enabled at the provider. The reason is shown to them.
  - **Non-anonymous members** get audio and video. Before their first unmute or camera-on they see a
    one-time warning that everyone will hear them and see their name (and face).
  - **The creator of an anonymous group** joins audio-only (SCRUM-332).
  - A group **can't be switched to anonymous** while people are in one of its video calls (SCRUM-332).
- **Idle auto-expiry.** A grant with no activity for `VIDEO_SPEAKING_GRANT_IDLE_TIMEOUT` seconds
  (default 300, minimum 30) is reverted by a scheduled sweep that runs every minute. The member's
  client pings while they are unmuted or on camera, and shows a countdown in the last minute.
- **One cap of 25.** The same number caps group membership (`GROUP_THERAPY_MAX_USERS`) and the video
  room (`ConstantsEnum::groupTherapyVideoMaxParticipants`). Daily enforces it as the room's
  `max_participants`. Chime has no room-size setting, so the app enforces it at join.
- **No Daily owner tokens in group calls** (SCRUM-331). An owner's browser could otherwise change
  anyone's permissions and bypass the minor and anonymity rules.

## What was built

| Ticket | What |
|---|---|
| SCRUM-318 (a) | Unified 25 cap for group membership and the video room |
| SCRUM-319 (b) | `video_session_speaking_grants` (append-only audit, `last_activity_at` mutable) and `video_session_hand_raises`; `group_therapy_user.was_minor_at_join` snapshot |
| SCRUM-320 (c) | Research spike: live capability updates on both providers (no design change needed) |
| SCRUM-321 (d) | Receive-only join for ordinary members; Chime app-level cap |
| SCRUM-322 (e) | `VideoProviderInterface::updateParticipantCapabilities()` on both adapters |
| SCRUM-323 (f) | Raise/lower hand, grant/revoke endpoints and broadcasts (`HandRaisedEvent`, `HandLoweredEvent`, `SpeakingPermissionChangedEvent`) |
| SCRUM-324 (g) | Minor-grant hard-block; anonymous members audio-only; anonymous display names for every masked group participant |
| SCRUM-325 (h) | Idle auto-expiry sweep, `speaking_activity` endpoint, config |
| SCRUM-326 (i) | Removing a participant also clears their grant and hand-raise |
| SCRUM-331, 332 | No Daily owner tokens for group calls; anonymous creator audio-only; anonymity switch blocked mid-call |
| SCRUM-333 | The Laravel scheduler now runs in Docker (the `queue` container's Supervisor). Without it the idle sweep never fires |
| SCRUM-327 (j) | Frontend: watching/speaking states, raise hand, counsellor queue, reveal warning, idle notices, spotlight view |
| SCRUM-328 (k) | This closeout: cross-provider regression suite, this doc |

**HTTP routes** (all under `auth`). Every route resolves the acting user from the session, and moderation
targets are bare ids resolved only from the call's own live participants:

| Route | Who | Throttle |
|---|---|---|
| `POST /sessions/{id}/video/hand/raise` · `/hand/lower` | the member themselves | 30/min |
| `POST /sessions/{id}/video/participants/{userId}/grant-speaking` · `/revoke-speaking` | any active counsellor of the group | 20/min |
| `POST /sessions/{id}/video/speaking-activity` | the granted member themselves | 12/min |

**Join response.** `POST /sessions/{id}/video/join` returns the provider's credentials plus a
`participant` key describing the joiner (all display-only; the provider token is what enforces):
- what they may do: `receiveOnly`, `canSendAudio`, `canSendVideo`, `isAnonymous`, `canRaiseHand`,
  `isMinorMember`, `canModerate`, `speakingGrantIdleTimeout`;
- the hands and speakers already live in the call: `raisedHandUserIds`, `speakerUserIds`.
  These are always present, but populated only for a counsellor.

**Broadcasts** go on the existing `sessions.{id}` private channel and carry ids only, never names:
- `video-session.hand-raised` and `video-session.hand-lowered`;
- `video-session.speaking-permission-changed`, which carries `canSendAudio`, `canSendVideo` and
  `reason` (null on a grant; `MANUAL`, `IDLE_AUTO_EXPIRY` or `PARTICIPANT_REMOVED` on a revoke).

**Frontend.** All in `VideoCallPanel.vue`, `useVideoSession.js` and the provider clients, plus a
presentational `VideoTile.vue`:
- **Members:**
  - a "You are watching" card with raise/lower hand (minors get an explanation instead);
  - once granted, a "You can speak now" card with mute (and camera, unless anonymous);
  - the reveal warning, the idle countdown, and expiry or revoke notices.
- **Counsellors:** a "Raised hands" list with **let speak**, and a "Speaking" list with **stop**.
- **Everyone:** **focus** on any tile shows that person large, with everyone else in a vertically
  scrollable side strip. It is local to each viewer.

## Regression matrix result (TT-3.2f-k closeout)

`tests/Feature/GroupVideoSpeakingModerationRegressionTest.php` runs every scenario through the real
HTTP routes and the **real** `DailyVideoProvider`/`ChimeVideoProvider` adapters, for **both
providers**. Only the transport is faked (`Http::fake()` for Daily, an in-memory `ChimeClient` for
Chime). Its assertions check what each provider is **told** to allow each user to send, not just our
database. The fakes model each provider's protocol as our adapters assume it, so they can't catch
a wrong assumption about the real provider. Only a live sandbox pass can (SCRUM-317).

| Scenario | Daily | Chime |
|---|---|---|
| Member joins receive-only; counsellors and creator can send | ✅ | ✅ |
| Raise hand → grant (counsellor A) → audio+video live → revoke by counsellor B → receive-only; lower hand | ✅ | ✅ |
| Minor member: watches, raise-hand 422, grant 422, never sends | ✅ | ✅ |
| Anonymous member granted audio-only; non-anonymous audio+video | ✅ | ✅ |
| Anonymous group's creator joins audio-only | ✅ | ✅ |
| Idle sweep reverts an unused grant (reason `IDLE_AUTO_EXPIRY`); an active speaker keeps theirs | ✅ | ✅ |
| Removal closes grant + hand-raise, ejects at the provider, leaves nothing in a later counsellor's snapshot | ✅ | ✅ |
| Unified cap pinned at 25 (provider-independent) | ✅ | ✅ |
| Daily room created with `max_participants` = 25 | ✅ | n/a |
| Chime admits the 25th active participant, refuses the 26th (422) | n/a | ✅ |

Per-action edge cases live in each action's own unit tests:
- stale reconnect rows (SCRUM-330), provider-failure semantics per revoke reason, concurrency locks;
- the mid-call anonymity guard, and broadcast payloads.

Full suite at closeout (2026-10-31): **2027 passed**.

## Known, accepted limitations

- **No live provider credentials in this dev environment.** This is the same as the base feature
  (SCRUM-317). Browser QA drives the real backend actions, broadcasts and UI, but stubs the provider
  SDK in the browser. Real audio and video still need a sandbox-credential pass.
- **Minors can't be let speak** until SCRUM-313 / SCRUM-329.
- **Reconnects leave stale participant rows** (SCRUM-330). A reconnecting member gets a fresh
  receive-only join and loses any grant they had.
  - The idle sweep closes a stale row's grant locally but never mutes a reused Chime attendee (noted
    on SCRUM-330).
  - Someone who leaves during a counsellor's own reconnect can linger in that counsellor's lists until
    acted on.
- **A provider failure while minting a token** leaks the raw provider error and leaves an open
  participant row (SCRUM-335).
- **Real user ids are visible in calls, and Daily lets participants rename themselves** (SCRUM-334).
  These are pre-existing, and tracked there.
- **Moderation is queue-only in the UI.** A counsellor can't "let speak" a member who hasn't raised a
  hand, though the backend allows it.

## How to try it out

### Test data

Seeded by `DatabaseSeeder::createGroupVideoCallDemoData()`. See `documentation/seeded-data.md`'s
"Group video call + participant removal + speaking permission" section.

| Username | Password | Role |
|---|---|---|
| `group_video_call_demo_counsellor` | `password` | The group's counsellor: sees the queue, lets members speak, stops them, removes them |
| `group_video_call_demo_creator` | `password` | The group's creator: joins able to speak |
| `group_video_call_demo_member` | `password` | Adult member: watches, raises a hand; gets audio+video when let speak |
| `group_video_call_demo_anonymous_member` | `password` | Anonymous member: shown as "Anonymous"; audio only when let speak |
| `group_video_call_demo_minor_member` | `password` | Minor member: watches only, no raise-hand |

"Group Video Call Demo" has an online, in-progress session. Set `DAILY_API_KEY`/`DAILY_DOMAIN` (or
switch to Chime with AWS credentials) in `.env.docker` for a real call. To see moderation work end to
end, the scheduler must be running: it is, in the `queue` container (SCRUM-333).

### Steps

1. Open "Group Video Call Demo" → chat page, in two browsers:
   - as `group_video_call_demo_counsellor`;
   - as `group_video_call_demo_member`.
   Both click **join video**.
2. **Member:** the "You are watching" card shows, with no mute or camera buttons. Click **raise
   hand**; it changes to "lower hand".
3. **Counsellor:** the member appears under **Raised hands**. Click **let speak**; they move to
   **Speaking**.
4. **Member:** "You can speak now" shows, with **unmute** and **turn camera on**. Clicking
   **unmute** first shows the "Before you start speaking" warning. Confirm, and others hear you. The
   warning doesn't appear again in this call.
5. **Counsellor:** click **stop**. The member's mic and camera go off, and they see "A counsellor
   ended your permission to speak."
6. **Idle expiry:** let the member speak again and leave them muted. In the last minute a countdown
   appears. When the sweep runs, they see "…because you stayed muted for a while." For a faster
   try, set `VIDEO_SPEAKING_GRANT_IDLE_TIMEOUT=30` in `.env.docker` and restart `php` and `queue`.
7. **Anonymous member:** as `group_video_call_demo_anonymous_member`, raise a hand and get let speak.
   Only **unmute** appears, with "Audio only: your camera stays off because you are anonymous in
   this group." There is no reveal warning, because the name is already masked.
8. **Minor member:** as `group_video_call_demo_minor_member`, there is no raise-hand button, only
   "Speaking in group video isn't available to members under 18 yet."
9. **Removal:** as the counsellor, click **remove** on a member with a raised hand or a grant. They
   see "You were removed from this call", and they disappear from both lists.
10. **Spotlight:** click **focus** on any tile. It fills the main area, and everyone else stacks in
    a scrollable strip beside it. **show all** returns to the grid.

### What a successful result looks like

- A member can never be heard or seen unless a counsellor let them speak. This is enforced by the
  provider, so devtools can't get around it.
- Every grant, revoke, hand-raise and expiry shows up on the other side within a moment, with no
  page reload.
- Anonymous members never show video, and minors are never offered speaking.
- Nothing about who raised a hand or who is speaking is ever shown with a real name for a masked
  participant.
