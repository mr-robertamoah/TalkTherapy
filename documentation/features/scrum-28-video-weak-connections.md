# TT-3.3: Video on weak connections (SCRUM-28)

A video call (1:1 or group) now copes with a poor line instead of freezing or silently dropping:
the person sees how good their connection is in words, the call reconnects by itself for a while
after a drop, the camera pauses automatically while the line is weak (audio carries on), anyone can
choose "audio only" to save data, and the others in the call see why someone's video stopped.

Delivered in four PRs: SCRUM-458 (TT-3.3a, fake provider), SCRUM-459 (TT-3.3b, connection fixes and
reconnect), SCRUM-460 (TT-3.3c, audio only), SCRUM-461 (TT-3.3d, this closeout). Builds on the base
video feature (`scrum-26-video-calling.md`) and group video (`scrum-27-group-therapy-video.md`,
`scrum-314-full-membership-group-video.md`). Decisions and their reasons are in
`documentation/decision-log.md` under "SCRUM-28 -- video on weak connections: planning" and the
SCRUM-458/459/460/461 entries.

Almost all of it is front end. Nothing new is stored apart from one column
(`video_session_participants.removed_at`, SCRUM-459), there is no new Reverb event, and video never
changes `Session.status`, attendance, counsellor earnings, transactions or organisation invoices.

## What was built

- **Fake video provider (SCRUM-458).** `VIDEO_PROVIDER=fake` swaps only the provider adapter
  (`App\Services\Fake\FakeVideoProvider` on the server, `resources/js/Video/FakeVideoClient.js` in
  the browser); every server check on join (participation, payment, consent, availability) runs
  unchanged. It is refused outside `local`/`testing` (every join fails, and a critical log line is
  written at boot). In a dev build the browser gets `window.__ttFakeVideo` to simulate quality,
  other people and drops. The call uses your real camera and microphone, or a drawn placeholder.
- **Quality in words (SCRUM-459).** Daily and Chime readings are mapped to one scale
  (`good | poor | bad | unknown`) and shown as "Connection: good / weak / very weak / unknown".
  Screen readers are told through a live region in `VideoCallPanel.vue`, at most once per 20 s,
  except a drop to "very weak", which is always said.
- **Classified stops (SCRUM-459).** Each client sorts a stop into recoverable (a lost connection,
  an expired token) or terminal (removed, call ended, room expired, anything unknown). Terminal
  stops never reconnect: "You were taken out of this video call." or "The video call ended."
- **Bounded reconnect (SCRUM-459).** The provider reconnects by itself first ("Reconnecting…").
  After a recoverable stop the app rejoins: up to 3 attempts (~2/5/10 s), each waiting for the
  browser to be online, within 60 s, each a full `sessions.video.join`. Then "We couldn't
  reconnect" with "try again" and a hint that text chat still works. Payment, consent and
  validation refusals and device errors are never retried.
- **Server rejoin guard (SCRUM-459).** An automatic rejoin sends `rejoin: true` and the
  `videoSessionId` from the earlier join. The server refuses it (422, `rejoinRefused: 'ended' |
  'removed'`) if that call has ended or the person was removed and hasn't joined since, so a browser
  that was offline can't undo a removal or restart an ended call. Each rejoin closes the person's
  older open row. A manual "join video" / "try again" is unaffected.
- **Automatic audio only (SCRUM-460).** After ~10 s of a weak line, or at once on a very weak one,
  the camera pauses; the microphone is never touched. The person sees "Video paused to keep your
  call going -- audio continues" with "Turn video back on". Video comes back after ~30 s of a good
  line only if the app paused it, only while the server still allows this person video, only if
  the live-update connection stayed up, and at most twice per call. A camera the person turned off
  is never touched. Rules: `resources/js/Video/audioOnlyPolicy.js`.
- **Peer labels (SCRUM-460).** The others see "Video paused -- weak connection" on that person's
  tile. It is sent as a provider data message carrying only `{ttNetworkPause: bool}`, attributed by
  the provider's own sender id, and shown only while the provider says that person's video is off.
- **Manual "audio only" (SCRUM-460).** Any participant (watchers included) can turn their own
  camera off and stop receiving everyone's video: "Saving data -- video is off. Audio continues.";
  other tiles say "Video hidden to save data". "Turn video back on" undoes it.
- **Rejoin keeps choices (SCRUM-460).** An automatic rejoin keeps the person's own camera-off, any
  mute, the app's pause (while video is still allowed), "audio only" and the restore count. A
  manual "try again" starts fresh.
- **Closeout regression (SCRUM-461).** `tests/Feature/VideoRejoinLeavesBillingUntouchedTest.php`:
  for a 1:1 and a group session, two people each rejoining automatically three times in a row
  without leaving leave `Session.status`, `session_attendances`, counsellor earnings, transactions
  and organisation invoice lines unchanged; `FailUnheldSessionsAction` treats the session exactly as
  it treats the same session with no video; the call still counts two people (the Chime capacity
  head count); one call epoch; each rejoin closed the row before it (one open row each, none marked
  removed).

## How to try it

### Setup (local only)

1. In `.env.docker`, set `VIDEO_PROVIDER=fake` (refused anywhere but `local`/`testing`).
2. `docker compose up -d --force-recreate php`.
3. Use the Vite dev server (`http://localhost:8000` with `vite` running): `window.__ttFakeVideo`
   only exists in a dev build.
4. Afterwards, set `VIDEO_PROVIDER` back and recreate `php` again.

### Where

- **1:1:** log in as `video_call_demo_client` (or `video_call_demo_counsellor`), password
  `password`. Open "Video Call Demo Therapy" from `/therapies`, then its chat page
  (`/therapies/{id}/chat`), then "join video".
- **Group:** log in as `group_video_call_demo_counsellor` (or `_creator`, or `_member` to try it as a
  receive-only watcher), password `password`. Open "Group Video Call Demo", then its chat page
  (`/group-therapies/{id}/chat`), then "join video".

Both sessions are seeded already in progress. Open the browser console once you are in the call;
`window.__ttFakeVideo.state()` shows what the fake thinks is happening at any point.

### Scripts

Each starts from a freshly joined call (leave and "join video" again between scripts, or reload).

**1. Quality in words**
1. `__ttFakeVideo.setQuality('poor')` -- the header says "Connection: weak".
2. `__ttFakeVideo.setQuality('bad')` -- "Connection: very weak" (a screen reader is told at once).
3. `__ttFakeVideo.setQuality('good')` -- "Connection: good".

**2. Provider reconnecting by itself**
1. `__ttFakeVideo.simulateReconnecting(true)` -- "Reconnecting…"; the call stays up.
2. `__ttFakeVideo.simulateReconnecting(false)` -- back to "Connection: …".

**3. Automatic rejoin after a drop**
1. `__ttFakeVideo.dropRecoverable()` -- "Reconnecting… (attempt 1)", then the call is back.
2. In the network tab, the rejoin is a `POST .../video/join` with `rejoin: true` and the
   `videoSessionId`.

**4. Running out of attempts**
1. `__ttFakeVideo.failNextJoin('TimeoutError', 5)`, then `__ttFakeVideo.dropRecoverable()`.
2. The attempt number climbs, then "We couldn't reconnect" with "try again" and the text-chat hint.
3. `__ttFakeVideo.failNextJoin(null)`, then "try again" -- the call is back.

**5. A device error is not retried**
1. `__ttFakeVideo.failNextJoin('NotAllowedError')`, then `__ttFakeVideo.dropRecoverable()`.
2. It stops at once with the camera/microphone permission message; no further attempts.

**6. Terminal stops**
1. `__ttFakeVideo.stopTerminal('removed')` (or `'ejected'`) -- "You were taken out of this video
   call."; no reconnect.
2. Rejoin, then `__ttFakeVideo.stopTerminal('meeting-ended')` (or `'room-expired'`) -- "The video
   call ended."; no reconnect.

**7. Server rejoin guard**
1. As `group_video_call_demo_member`, join the group call.
2. Take that browser offline (devtools Network → "Offline"), then `__ttFakeVideo.dropRecoverable()`
   -- the rejoin waits for the browser to be online.
3. Two fake browsers don't see each other, so the counsellor has no tile to press "remove" on;
   remove the member on the server instead, as the group's counsellor would
   (`docker compose exec -u www-data -e HOME=/tmp php php artisan tinker`):
   ```php
   $s = App\Models\Session::whereHasMorph('for', [App\Models\GroupTherapy::class], fn ($q) => $q->where('name', 'Group Video Call Demo'))->latest('id')->first();
   App\Actions\Video\RemoveParticipantFromVideoSessionAction::new()->execute($s, App\Models\User::firstWhere('username', 'group_video_call_demo_counsellor'), App\Models\User::firstWhere('username', 'group_video_call_demo_member')->id);
   ```
4. Put the browser back online. The rejoin is refused (422, `rejoinRefused: 'removed'`) and the
   member sees "You were taken out of this video call." Their own "join video" still works.

**8. Automatic audio only**
1. Camera on. `__ttFakeVideo.setQuality('bad')` -- the camera pauses at once; the banner "Video
   paused to keep your call going -- audio continues" with "Turn video back on"; your tile says
   "Video paused -- weak connection"; the microphone stays on (`state().audioOn` is true).
   (`setQuality('poor')` does the same after ~10 s.)
2. `__ttFakeVideo.setQuality('good')` and wait ~30 s -- the camera comes back by itself.
3. Repeat twice more: on the third weak spell the camera stays paused until you press "Turn video
   back on" (at most two automatic restores per call).
4. Turn your camera off yourself, then `setQuality('bad')` and `setQuality('good')`: nothing
   changes -- a camera you turned off is never touched.

**9. Peer labels**
1. `const id = __ttFakeVideo.addParticipant()` -- a placeholder tile appears.
2. `__ttFakeVideo.setRemoteVideo(id, false)` -- the tile says "Camera off".
3. `__ttFakeVideo.setRemoteNetworkPause(id, true)` -- it says "Video paused -- weak connection".
4. `__ttFakeVideo.setRemoteVideo(id, true)` -- the label goes (it only shows while their video is
   off). `__ttFakeVideo.setRemoteNetworkPause(id, { ttNetworkPause: 'yes' })` -- ignored
   (malformed).
5. While your own camera is paused by script 8, `state().sentNetworkPause` is `true`.

**10. Manual "audio only"**
1. Add a participant as in script 9. Press "audio only".
2. "Saving data -- video is off. Audio continues."; the other tile says "Video hidden to save data";
   `state().audioOnly` is `true`, `state().receivingVideo` is `false`.
3. "Turn video back on" -- everyone's video returns, and your camera if it was on before.

**11. Rejoin keeps choices**
1. Mute, turn your camera off, and press "audio only".
2. `__ttFakeVideo.dropRecoverable()` -- after the rejoin you are still muted, camera off, in audio
   only.
3. Leave, "join video" again -- a manual join starts fresh.

## Known limits

- **Two fake browsers don't see each other.** Remote people only come from `addParticipant()`, so
  what the *other* side sees of a pause, a label or audio only is covered by unit tests
  (`tests/js/*.test.mjs`) and the real-provider pass, not by the fake.
- **Real-provider behaviour is unverified.** There are no Daily/Chime sandbox keys in this
  environment. Everything provider-specific (Daily `network-connection` values, Chime reconnect
  events, data-message sender ids, unsubscribing video) is listed in
  `documentation/operations/video-real-provider-checklist.md` for SCRUM-317.
- The composable's state machine (`useVideoSession.js`) has no JS test harness; it was exercised in
  the browser with the fake provider. The pure rules (`connectionPolicy.js`, `audioOnlyPolicy.js`)
  have node tests (`npm run test:js`).
- A granted group speaker who goes through an app-level rejoin has to raise their hand again;
  keeping the floor needs speaking grants tied to the person (SCRUM-462, later).
- Chime's own reconnect timeout stays at its 120 s default.

## Test data

No new seed data. Use the existing video fixtures (see `documentation/seeded-data.md`, "Video
call" and "Group video call"), all with password `password`:

| Account | Use it for |
|---|---|
| `video_call_demo_client` | 1:1 call on "Video Call Demo Therapy" (adult client, no consent gate). Scripts 1-6 and 8-11. |
| `video_call_demo_counsellor` | The same call from the counsellor's side. |
| `group_video_call_demo_counsellor` | Group call on "Group Video Call Demo"; script 7's counsellor. |
| `group_video_call_demo_member` | Group call as a receive-only watcher ("audio only" still offered); script 7's removed member. |
| `group_video_call_demo_creator` | Group call as the creator (camera and microphone). |

Both sessions are seeded in progress (status in session), online, so "join video" is offered
straight away.
