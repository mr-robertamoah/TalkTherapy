# TT-3.1: 1:1 Video/Audio Calling (SCRUM-26)

A therapy session can be conducted as a live video call between the client and their assigned
counsellor, alongside the existing chat. Built on a dual video-provider architecture chosen after
cost/compliance research (LiveKit Cloud's HIPAA BAA only ships on its $500/mo Scale tier; Daily.co
and Amazon Chime SDK both offer a self-serve BAA far more cheaply at this platform's launch
scale) — both providers are actively maintained, not one default plus an unused fallback.

Guardian/minor consent enforcement for video is a related but separate feature — see
`documentation/features/scrum-278-guardian-video-consent.md` (TT-3.1e) for that layer. This doc
covers the base call infrastructure (TT-3.1a-d) and this closeout (TT-3.1f).

## What was built

- **Dual-provider backend** (TT-3.1a): one `App\Contracts\VideoProviderInterface` (`createRoom`,
  `createParticipantCredentials`, `endRoom`), with `App\Services\Daily\DailyVideoProvider` and
  `App\Services\Chime\ChimeVideoProvider` (AWS Chime SDK) adapters. `VideoServiceProvider` is the
  ONE place that reads `config('video.provider')` (`VIDEO_PROVIDER` env var: `daily` or `chime`)
  to decide which adapter the container binds — every other class depends on the interface only,
  never a concrete provider class. This is a **deployment-level config choice, not a live
  per-session runtime switch** (architect decision, 2026-09-11) — changing it requires a
  redeploy/restart, not a per-call toggle.
  - `VideoSession`/`video_session_participants` data model is deliberately N-participant-ready,
    not hardcoded to 2 — so TT-3.2 (group video) can relax a business-rule constant rather than
    needing new schema.
  - The low-frequency "call started/ended" notification (`VideoSessionStatusChangedEvent`)
    broadcasts ONLY on the existing per-session `sessions.{id}` `PrivateChannel` — deliberately
    NOT also on the busier per-therapy/group `PresenceChannel` that chat/roster/topic traffic
    already uses, to avoid fragmenting a session's real-time traffic across channels. The actual
    WebRTC media signaling (SDP/ICE) never touches Reverb at all — each provider's own SDK/servers
    handle it entirely.
- **Payment-gate integration** (TT-3.1b): joining video reuses
  `EnsureStrictPaymentGateSatisfiedAction`/`EnsureUserCanAccessTherapyContentAction` exactly as
  messaging already does — a client can't bypass a paid session's gate via video just because
  it's a different route.
- **Frontend video client** (TT-3.1c): a Vue composable (`useVideoSession.js`) wrapping whichever
  SDK is active behind one common event surface — permission prompts, connection-quality
  indicator, join/leave UI, laid out alongside the existing chat.
- **Disconnect/reconnect handling** (TT-3.1d): a transient media disconnect (and the resulting
  reconnect, which the frontend implements as a plain re-join of the same still-open `VideoSession`
  epoch) never mutates `Session.status` — only the existing end-session action does. Proven at the
  layer that can actually enforce it: repeatedly calling Join/Leave/EndVideoSessionAction (exactly
  what a disconnect-then-reconnect cycle does from the backend's point of view) never touches
  `Session.status`, across multiple cycles in a row, and never creates a second `VideoSession` row
  for the same disconnect/reconnect epoch (`tests/Unit/VideoActionsNeverMutateSessionStatusTest.php`).
- **Closeout** (TT-3.1f, this ticket): regression pass across a-d (TT-3.1e had also fully landed
  by the time this closeout ran — all 7 of its own sub-tickets, SCRUM-280 through 286, are Done —
  so this closeout additionally confirms the base flow and the consent layer coexist correctly),
  seed data, and this doc.

## Regression matrix result (TT-3.1f)

- **Full Pest suite**: 1838 passed, no regressions.
- **Broadcast-channel separation confirmed by design, not just by test**: `VideoSessionStatusChangedEvent`
  shares the `sessions.{id}` channel NAME with exactly one pre-existing event —
  `SessionUpdatedEvent`, whose own `broadcastOn()` returns both the `PresenceChannel` for
  `therapies.{id}`/`groupTherapies.{id}` AND a `PrivateChannel` for `sessions.{id}` (the other
  chat/topic event, `SessionTopicSetEvent`, only ever broadcasts on the `PresenceChannel`, never
  on `sessions.{id}`, so it was never actually the relevant pairing here). Video and
  `SessionUpdatedEvent` are dispatched from entirely separate call sites in separate classes
  (`JoinVideoSessionAction`/`EndVideoSessionAction` vs. `SessionService`) with no shared mutable
  state — confirmed by direct code inspection, not a synthetic combined test. (A literal "both
  fire together" Pest test was attempted during this closeout and abandoned: `SessionUpdatedEvent`
  dispatches via the raw `broadcast()` helper + `->toOthers()`, which Laravel's `Event::fake()`
  does not intercept — testing it properly would require introducing `Broadcast::fake()`, a
  pattern with zero precedent anywhere in this codebase, for marginal assurance beyond what the
  architecture already guarantees by construction. Decision log has the full reasoning.)
- **Disconnect/reconnect invariant**: already covered, unchanged, still passing
  (`VideoActionsNeverMutateSessionStatusTest.php`).
- **Both providers**: `DailyVideoProviderTest.php`/`ChimeVideoProviderTest.php` cover each
  adapter's own request-shaping/response-mapping logic independently (mocked client, no real
  credentials needed); `VideoSessionControllerTest.php`/`JoinVideoSessionActionTest.php`/etc. cover
  the provider-agnostic orchestration layer once, since `VideoServiceProvider`'s config-driven
  binding means the orchestration code is identical regardless of which concrete provider is
  bound (parametrizing those tests over both providers was reviewed and explicitly rejected during
  TT-3.1d — see that test file's own comment — since it would run byte-for-byte the same code
  twice with no differential coverage).

## Known, accepted limitation: no live provider credentials in this dev environment

Neither `DAILY_API_KEY`/`DAILY_DOMAIN` nor `CHIME_AWS_ACCESS_KEY_ID`/`CHIME_AWS_SECRET_ACCESS_KEY`
are configured in this Docker dev environment's `.env.docker` (first flagged as a QA limitation,
not a defect, during TT-3.1c). This means:
- Every unit/feature test mocks the provider client directly (Mockery/a fake `VideoProviderInterface`
  implementation) — genuinely provider-agnostic, real coverage of this app's own logic.
- A live, real end-to-end video call (actual WebRTC media connecting) cannot be manually verified
  in this environment for EITHER provider without real credentials — this is an infrastructure/
  credentials gap, not a code gap. Reachable manually today: the join UI, the payment/consent
  gates, and the room-creation call being attempted against whichever provider is configured
  (which will itself fail cleanly with invalid credentials, not crash the app).
- To actually exercise a live call end-to-end: populate real `DAILY_API_KEY`/`DAILY_DOMAIN` (or
  the Chime AWS credentials) in `.env.docker`, restart the `php` container so the new env values
  are read, then follow the steps below.

## How to switch the active provider

Set `VIDEO_PROVIDER=daily` or `VIDEO_PROVIDER=chime` in `.env.docker` (or `.env` outside Docker),
then restart the `php` container (`docker compose restart php`) — env vars are read at request
time via `config('video.provider')`, not cached by default in this dev setup, but the running
container process needs to be restarted to pick up a `.env.docker` file change. This is a
deployment-level choice per `VideoServiceProvider`'s own design, not a per-session runtime toggle.

## How to try it out

### Test data

Seeded by `DatabaseSeeder::createVideoCallDemoData()` — see `documentation/seeded-data.md`'s
"Video call (SCRUM-279)" section:

| Username | Password | Role |
|---|---|---|
| `video_call_demo_client` | `password` | An adult client (no guardian-consent complexity) |
| `video_call_demo_counsellor` | `password` | The assigned counsellor |

"Video Call Demo Therapy" already has an immediately in-progress, online session, so "join video"
is reachable without any setup.

For the guardian-consent layer specifically, see `scrum-278-guardian-video-consent.md`'s own
`video_consent_demo_*` accounts instead — this feature's own seed is deliberately the plain,
no-consent-complexity case.

### Steps

1. Log in as `video_call_demo_client` (or `video_call_demo_counsellor`), open "Video Call Demo
   Therapy" → chat page → click "join video."
2. With real provider credentials configured: grant camera/mic permissions, the call connects,
   the other party can join from their own account to test a real two-party call.
3. Leaving and rejoining (simulating a disconnect) reuses the same call rather than starting a new
   one; ending the call is a distinct, explicit action from a transient disconnect.

### What a successful result looks like

- Whichever provider is configured via `VIDEO_PROVIDER` is the one actually used — verified at the
  code level (one config read, one container binding), not per-call.
- A client can't join a paid session's video call without having satisfied the same payment gate
  messaging already enforces.
- A transient disconnect-then-reconnect never changes the session's own status, and never creates
  a duplicate `VideoSession` row for the same call.
- The low-frequency call-started/ended notification reaches the existing `sessions.{id}` channel
  only — chat/roster/topic traffic on the busier per-therapy channel is untouched by video
  signaling volume.

## Group video (TT-3.2, SCRUM-27)

Group video is a separate feature built on top of this base infrastructure — see
`documentation/features/scrum-27-group-therapy-video.md` for its own scope (v1 is
counsellor-team + optional creator, not full-membership), what was built (authorization,
counsellor-only termination, in-call participant removal), test data, and known limitations.
