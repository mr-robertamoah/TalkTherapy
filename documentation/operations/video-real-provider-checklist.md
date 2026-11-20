# Video: real-provider checklist (SCRUM-317)

Everything about video that could only be checked against a live Daily or Chime account. This
environment has no sandbox keys, so the features below were built and tested with the fake provider
(`VIDEO_PROVIDER=fake`, SCRUM-458) and unit tests. SCRUM-317 is the single tracked place this gets
done: the full 1:1 and group matrix (join, leave, end, remove, raise hand / let speak, guardian
consent) on both providers, plus the items below.

How to run it: put sandbox keys in `.env.docker` (`VIDEO_PROVIDER=daily` or `chime` and that
provider's keys, see `.env.docker.example`), `docker compose up -d --force-recreate php queue
reverb`, and use two real browsers (two people, two machines or two profiles) with the seeded
accounts in `documentation/seeded-data.md` ("Video call", "Group video call"). To make a line weak,
use the browser's network throttling (devtools → Network → custom profile, e.g. 100 kbps / 30%
loss) or the OS's own (e.g. `tc netem` on Linux). Tick each item and note anything that differs in
`documentation/decision-log.md`.

Sources: decision-log entries SCRUM-458, SCRUM-459, SCRUM-460 (video on weak connections, SCRUM-28);
feature doc `documentation/features/scrum-28-video-weak-connections.md`.

## Daily

- [ ] **Token / room expiry is reported as expected** (SCRUM-459). Mint a short-lived room or token
  (or let one expire) during a call and log the `error` event's `errorMsg.type`. Expected: token
  expiry as `exp-token` (recoverable -- the app rejoins, the server mints a new token only while
  the session is in progress), room expiry as `exp-room` (terminal, "The video call ended."). If
  Daily reports either as `ejected`, the classification in `resources/js/Video/connectionPolicy.js` needs a
  change.
- [ ] **`network-connection` event values** (SCRUM-459). Throttle the network hard, then restore.
  Log every `network-connection` event: which `event` values arrive (`interrupted`, `connected`,
  ...), and that "Reconnecting…" shows while interrupted and clears when connected.
- [ ] **`networkState` → quality mapping** (SCRUM-459). With throttling, the header should go
  "Connection: good" → "weak" (Daily `warning`) → "very weak" (`bad`), and back.
- [ ] **Unsubscribing / resubscribing video works for everyone, including newcomers** (SCRUM-460).
  Person A presses "audio only": A receives no video from anyone (devtools network traffic drops),
  and someone who joins *after* that is also not received. "Turn video back on" brings every tile
  back, newcomers included.
- [ ] **`permissions.canSend` updates live after a revoke** (SCRUM-460). In a group call, let a
  member speak, wait until the app has paused their camera (throttle them), then revoke the floor.
  The member's `participants().local.permissions.canSend` must lose video/audio at once, and the
  app must not turn their camera back on when their line recovers.
- [ ] **Remote `tracks.video.state` reaches `off` promptly** (SCRUM-460). When A's camera pauses
  (throttle A), B's tile for A should change within a couple of seconds; "Video paused -- weak
  connection" only shows once Daily says A's video is off.
- [ ] **`app-message.fromId` matches the tile** (SCRUM-460). With three people, throttle A: only
  A's tile (on B's and C's screens) gets "Video paused -- weak connection", never another.
- [ ] **Provider reconnect keeps a speaking grant** (SCRUM-28 planning). A granted member's short
  drop (under the provider's own reconnect window) keeps the floor; only an app-level rejoin makes
  them raise a hand again (SCRUM-462).
- [ ] **`update-permissions` is keyed by our user id** (from the SCRUM-320 spike; left open in the
  SCRUM-328 closeout): the
  grant/revoke above actually reaches the right participant.

## Chime

- [ ] **`audioVideoDidStart` after Chime's own reconnect** (SCRUM-459). Cut the network for ~10 s
  and restore it. Log whether `audioVideoDidStart` fires again after `connectionDidBecomePoor` /
  reconnect; "Reconnecting…" must clear either way.
- [ ] **`connectionDidSuggestStopVideo` → "very weak"** (SCRUM-459). Throttle hard: the header says
  "Connection: very weak" and the camera pauses.
- [ ] **Recoverable vs terminal session status codes** (SCRUM-459). A lost connection ends in a
  rejoin; a removal (`AudioAttendeeRemoved`, a counsellor's "remove") and the meeting ending
  (`MeetingEnded`) never rejoin. Note any status code seen that isn't on the allow-list in
  `resources/js/Video/connectionPolicy.js` (`CHIME_RECOVERABLE_STATUS_CODES`; unknown is terminal).
- [ ] **A stopped local tile reaches others as removed** (SCRUM-460). When A's camera pauses, B's
  tile for A changes (via `videoTileWasRemoved` or the tile's own state), not a frozen frame.
- [ ] **Paused remote tiles behave, including later arrivals** (SCRUM-460). A presses "audio only":
  existing remote tiles pause, and a tile for someone who joins later starts paused too. "Turn
  video back on" unpauses all of them.
- [ ] **Data messages arrive with the right sender id** (SCRUM-460). With three people, throttle A:
  only A's tile gets "Video paused -- weak connection" (topic `tt-net-pause`,
  `senderAttendeeId` matches A's tile).
- [ ] **Data messages are allowed for receive-only attendees** (SCRUM-460). A receive-only group
  member (attendee capabilities `None` for audio/video) still receives the others' `tt-net-pause`
  labels. (A watch-only member never sends one; that is expected.)
- [ ] **Presence-before-tile** (SCRUM-460). Join with a camera on and watch the others' screens:
  note whether a brief "Camera off" flashes before the tile arrives.
- [ ] **Capacity counts people, not rows** (SCRUM-330). In a group call, have one person drop and
  rejoin several times; they must never be turned away as "maximum number of participants", and
  nobody else loses a place (the 25-person cap). The server side is covered by the SCRUM-330 cases
  in `tests/Unit/JoinVideoSessionActionTest.php`.
