import { computed, ref, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import axios from 'axios'
import { acquireChannel } from '@/Composables/sharedChannel'
import {
    DEVICE_ERROR_NAMES,
    QUALITY_GOOD,
    normalizeQuality,
    normalizeStop,
    qualityAnnouncement,
    reconnectDelay,
    RECONNECT_WINDOW_MS,
    shouldRetryJoin,
} from '@/Video/connectionPolicy'
import {
    AUTO_PAUSE,
    AUTO_RESTORE,
    autoActionStillValid,
    autoVideoDecision,
    isVideoAllowed,
    joinMediaState,
    nextAutoPauseSuspended,
    remoteNetworkPauseChanged,
    remoteVideoChanged,
    restoreBlocked,
} from '@/Video/audioOnlyPolicy'

// TT-3.1c/SCRUM-276: one composable, two interchangeable provider clients (Video/DailyVideoClient.js,
// Video/ChimeVideoClient.js) -- which one loads is decided entirely by the `videoProvider` Inertia
// shared prop (config('video.provider'), see HandleInertiaRequestsV2::share()), a deployment-level
// choice, never a runtime switch (mirrors TT-3.1a's own backend VideoServiceProvider binding).
// SCRUM-458: plus Video/FakeVideoClient.js for `videoProvider` 'fake' -- local dev and Playwright QA
// with no provider at all; same factory shape and callbacks.
//
// Deliberately NOT a formal JS interface/abstract class -- this codebase has no such precedent in
// resources/js, and there are only ever a handful of implementations, one chosen per deployment
// (Daily, Chime, and the local-only fake). Every client file must implement this exact factory shape instead, documented here rather than enforced by
// the language (mirrors useConnectionStatus.js's own established idiom of injecting callbacks at
// construction time, not the backend's PHP `interface VideoProviderInterface` -- PHP needing a
// real interface for container-bound DI doesn't imply an equivalent formalism is warranted here):
//
//   export default function createXVideoClient(callbacks) => {
//     join(credentials, { startVideoOff, startAudioOff }): Promise<void>
//                                            -- credentials is this provider's own raw shape,
//                                                exactly POST sessions.video.join's JSON response.
//                                                TT-3.3c/SCRUM-460: startVideoOff/startAudioOff
//                                                (default false) join with the camera off / muted
//                                                even if it may send -- an automatic rejoin keeps
//                                                the person's own camera-off and mute, and the
//                                                app's pause / "audio only" (audioOnlyPolicy.js's
//                                                joinMediaState()); toggles start them later.
//     leave(): Promise<void>                 -- local teardown only. Ending the room for everyone
//     end(): Promise<void>                      is the backend's own server-side provider API
//                                                call (see sessions.video.end) -- both are
//                                                deliberately identical local teardown on both
//                                                clients (verified against Daily/Chime's own docs,
//                                                2026-09: neither SDK has a client-side "end for
//                                                everyone" primitive of its own).
//     toggleMute(): boolean|Promise<boolean> -- returns the new isMuted state (async on Chime,
//                                                which starts a receive-only join's mic lazily)
//     toggleCamera(): boolean|Promise<boolean> -- returns the new isCameraOn state (same)
//     setCamera(on): boolean|Promise<boolean> -- TT-3.3c/SCRUM-460: the app turning the camera off
//                                                (a weak connection) or back on, never the mic --
//                                                Daily setLocalVideo(), Chime stop/
//                                                startLocalVideoTile(). Returns the new isCameraOn;
//                                                same permission/revoke-race handling as
//                                                toggleCamera().
//     setReceiveVideo(on): void|Promise      -- TT-3.3c/SCRUM-460: "audio only" -- stop (false) or
//                                                resume receiving everyone else's video, people who
//                                                join later included (Daily: audio-only track
//                                                subscriptions, see DailyVideoClient.js; Chime:
//                                                pauseVideoTile on every remote tile).
//     providerAllowsVideo?(): boolean|null   -- TT-3.3c/SCRUM-460, optional: whether the provider
//                                                itself still lets this person send video (Daily:
//                                                the local participant's permissions.canSend), null
//                                                or absent when it can't say. An automatic camera
//                                                restore is skipped on false.
//     sendNetworkPause(paused): void         -- TT-3.3c/SCRUM-460: tell everyone else our video is
//                                                (no longer) paused for the connection -- a provider
//                                                data message carrying exactly
//                                                { ttNetworkPause: boolean } (audioOnlyPolicy.js;
//                                                Daily sendAppMessage, Chime realtimeSendDataMessage
//                                                on topic 'tt-net-pause'). Best effort. No Reverb,
//                                                nothing stored or logged server-side.
//     disableLocalMedia(): void|Promise      -- TT-3.2f-j/SCRUM-327: stop sending audio+video
//                                                (a revoked speaking permission). join() reads
//                                                credentials.participant's canSendAudio/
//                                                canSendVideo to decide which devices to open
//                                                (Daily/Chime; the fake reads its own
//                                                provider-style credentials.canSend instead).
//     attachVideo(participantId, videoEl)    -- attach participantId's video stream to this
//                                                <video> element ('local' is always the calling
//                                                user's own id). Its own method (not a raw
//                                                MediaStreamTrack handed back via a callback)
//                                                because Daily and Chime attach video via
//                                                genuinely incompatible mechanisms (raw track vs.
//                                                tile-id video-element binding) -- forcing one
//                                                shape here would leak one provider's model into
//                                                the other's client.
//     destroy(): Promise<void>|void          -- full teardown + release all resources
//   }
//
//   callbacks passed into the factory:
//     onParticipantJoined({ id, isLocal, userId })  -- deliberately no provider-side name (SCRUM-334:
//                                                     a Daily participant can rename themselves
//                                                     from their own browser; Chime has none) --
//                                                     every label comes from our server, by userId
//                                                     (see resolveDisplayName() below). userId (TT-3.2c/
//                                                     SCRUM-310, SCRUM-357) is the participant KEY
//                                                     the backend minted (never a user id), never
//                                                     the provider's own tile/attendee id (`id`) --
//                                                     may be null for a brief window on Chime (its
//                                                     presence callback's externalUserId can lag
//                                                     the initial join) or if a provider response
//                                                     shape ever changes; removeParticipant() below
//                                                     is simply unavailable for that participant
//                                                     until/unless it arrives on a later update.
//     onParticipantLeft(id)
//     onVideoAvailable(id)                        -- this participant's video is ready;
//                                                     VideoCallPanel.vue should call attachVideo
//     onConnectionQualityChanged(quality)          -- TT-3.3b/SCRUM-459: 'good' | 'poor' | 'bad' |
//                                                     'unknown' -- the one shared scale; each client
//                                                     maps its SDK's own values through
//                                                     Video/connectionPolicy.js (dailyQuality(),
//                                                     chimeQuality()). Anything else is read as
//                                                     'unknown'.
//     onReconnecting(isReconnecting)               -- TT-3.3b/SCRUM-459: the provider's OWN SDK is
//                                                     reconnecting the call by itself (true) / has
//                                                     reconnected (false). The call stays
//                                                     'connected' meanwhile; the panel just says
//                                                     "Reconnecting…". If the SDK gives up it calls
//                                                     onDisconnected instead.
//     onRemoteVideoChanged(id, isOn)               -- TT-3.3c/SCRUM-460: a remote participant's
//                                                     camera stopped/started sending, from the
//                                                     provider's own track state (Daily
//                                                     tracks.video.state; Chime tile added/removed)
//                                                     -- the tile's camera-off placeholder. Only
//                                                     changes are reported.
//     onRemoteNetworkPause(id, paused)             -- TT-3.3c/SCRUM-460: a remote participant's
//                                                     sendNetworkPause(), attributed by the
//                                                     provider's OWN sender id (same id as their
//                                                     tile), never by anything in the payload; a
//                                                     malformed message is dropped by the client.
//     onError(error)                               -- a reportable problem that does NOT mean
//                                                     the call itself ended (e.g. Daily's own
//                                                     'nonfatal-error') -- surfaced, not recovered
//                                                     from
//     onDisconnected(stop)                         -- TT-3.1d/SCRUM-277: the LOCAL user's own
//                                                     connection to the provider ended
//                                                     unexpectedly (not because leave()/end()
//                                                     was called) -- see handleDisconnected()
//                                                     below for what this triggers.
//                                                     TT-3.3b/SCRUM-459: `stop` is the client's
//                                                     classification of why, from
//                                                     connectionPolicy.js's classifyDailyStop()/
//                                                     classifyChimeStop(): { recoverable, outcome:
//                                                     'reconnect'|'ended'|'removed'|'error',
//                                                     message, reason } (any other client builds
//                                                     it with recoverableStop()/terminalStop()).
//                                                     Only an allow-listed
//                                                     recoverable stop is ever rejoined; anything
//                                                     else (an unclassified value included) is
//                                                     terminal.
//                                                     Never a remote participant leaving (that's
//                                                     onParticipantLeft), and NEVER anything that
//                                                     touches Session.status -- this composable
//                                                     has no code path that ever could.
export default function useVideoSession(session) {
    // TT-3.2c/SCRUM-310: 'removed' is deliberately its own status, not folded into 'ended' -- the
    // architect's own explicit requirement is that being forcibly removed by a counsellor must
    // never look like a transient network disconnect (which reconnects) OR like the whole room
    // ending (which is symmetric/nobody's "fault"). Reached via the removed participant's own
    // listenForStatusChanges() handler below (TT-3.3b/SCRUM-459: or the provider's own "removed"
    // stop, or the server refusing an automatic rejoin -- see removedByCounsellor), never set
    // locally by removeParticipant() itself (that call is the COUNSELLOR's own action against
    // someone else, not self-directed).
    //
    // TT-3.3b/SCRUM-459: 'reconnect_failed' is where a dropped call lands once every automatic
    // rejoin within the reconnect window has failed -- the panel offers a "try again" that never
    // expires. Deliberately not 'error': nothing was refused, and the copy differs (it points at
    // the session's text chat meanwhile).
    const status = ref('idle') // idle | connecting | connected | payment_required | consent_required | ended | removed | error | reconnect_failed
    const participants = ref([])
    const isMuted = ref(false)
    const isCameraOn = ref(true)
    const connectionQuality = ref('unknown')
    const lastError = ref('')
    // TT-3.1d/SCRUM-277: layered independently on top of `status` rather than as its own status
    // value -- a reconnect attempt goes through the exact same 'connecting' -> 'connected'/'error'
    // states a first-time join does (same join(), same code path), this ref only exists so
    // VideoCallPanel.vue can show "reconnecting…" instead of "connecting to video…" for the
    // second case.
    //
    // TT-3.3b/SCRUM-459: also true while 'connected' when the provider's own SDK is reconnecting by
    // itself (onReconnecting), and through the waits between this composable's own rejoin attempts.
    const reconnecting = ref(false)
    // TT-3.3b/SCRUM-459: which automatic rejoin attempt is running (1-based), 0 when none -- the
    // panel shows "Reconnecting… (attempt N)".
    const reconnectAttempt = ref(0)
    // TT-3.3b/SCRUM-459: the latest thing worth telling a screen reader about the connection (a
    // quality change, a drop, a reconnect) -- VideoCallPanel.vue renders it in a polite live region.
    // Set only on meaningful changes (see announce() and its watchers below), never per SDK event.
    const announcement = ref('')
    // TT-3.3b/SCRUM-459: true only when 'removed' came from the participant-removed broadcast (a
    // counsellor or the system took this person out); false when the provider's own stop or a
    // refused rejoin said so -- the panel then uses neutral copy, since it can't say who did it.
    const removedByCounsellor = ref(false)

    // TT-3.2f-j/SCRUM-327: what THIS viewer may do in the call -- seeded from the join response's
    // own `participant` key (JoinVideoSessionAction::participantStateFor()), then kept current by
    // the speaking-permission broadcasts below. Display-only: the provider token is what actually
    // enforces sending, and every moderation endpoint re-authorizes on its own.
    const capabilities = ref(defaultCapabilities())
    const handRaised = ref(false)
    // Counsellor-only: our own backend user ids, never provider tile ids.
    const raisedHandUserIds = ref([])
    const speakerUserIds = ref([])
    // A one-line explanation of the viewer's last speaking-permission change ('' when none).
    const speakingNotice = ref('')
    // Seconds until an unused speaking grant is expired by the backend's idle sweep (null when the
    // viewer holds no grant). See startIdleTracking().
    const idleSecondsRemaining = ref(null)
    // True while a mute (micBusy) or camera (cameraBusy) change is still waiting on the provider
    // (Chime may be starting a device behind a permission prompt) -- that control is disabled
    // meanwhile, so a second click can't race the first into publishing while the UI says off.
    // TT-3.3c/SCRUM-460: one lock per device (see withMediaLock()), so an automatic camera pause or
    // restore never stops anyone muting. mediaError explains a failure.
    const micBusy = ref(false)
    const cameraBusy = ref(false)
    const mediaError = ref('')

    // TT-3.3c/SCRUM-460: audio only on a weak connection. isCameraOn is what the camera actually is;
    // cameraWanted is what the PERSON last chose -- the app may pause a wanted camera for the
    // connection (videoPausedForNetwork) and bring back only that, never one the person turned off.
    // audioOnly is the person's own mode: own camera off and nobody else's video received. Every
    // rule (timings, eligibility, the bounce cap) is in Video/audioOnlyPolicy.js.
    const cameraWanted = ref(true)
    const videoPausedForNetwork = ref(false)
    const audioOnly = ref(false)
    // Remote tile id -> { videoOff, networkPaused }: their camera per the provider, and their own
    // "paused for my connection" message. Keyed by the provider's ids, cleared as they leave.
    const remoteMedia = ref({})
    // Whether the server lets this person send video right now -- the pause notice shows only then.
    const videoAllowed = computed(() => isVideoAllowed(capabilities.value))

    let client = null
    let sessionChannel = null
    // SCRUM-357: everyone in a call is identified by a per-session PARTICIPANT KEY, never their
    // user id -- the provider's participant identity, the hand/speaking/removal broadcasts, the
    // join snapshot and the moderation routes all carry it (see App\Support\VideoParticipantKey).
    // So every `userId` on a participant and every entry in raisedHandUserIds/speakerUserIds below
    // is a participant key. The viewer's own key comes from the join response (state.key).
    let ownUserId = null

    function defaultCapabilities() {
        return {
            receiveOnly: false,
            canSendAudio: true,
            canSendVideo: true,
            isAnonymous: false,
            canRaiseHand: false,
            speakingNote: null,
            canModerate: false,
            speakingGrantIdleTimeout: 300,
        }
    }

    // A receive-only member who currently holds a speaking grant -- the only viewer the idle
    // sweep can ever affect (counsellors and the creator never hold grants).
    const holdsSpeakingGrant = computed(() => capabilities.value.receiveOnly && capabilities.value.canSendAudio)

    // SCRUM-334: participant key -> Promise of the server-resolved label (null when the server
    // wouldn't say). One lookup per key per join -- every tile of the same person shares it.
    let displayNames = new Map()

    function upsertParticipant({ id, isLocal, userId }) {
        const existingIndex = participants.value.findIndex((p) => p.id === id)

        // TT-3.2c/SCRUM-310: userId is patched in rather than no-op'd -- Chime's own presence
        // callback can report a null externalUserId on an early update before patching in the real
        // one on a later call.
        if (existingIndex !== -1) {
            if (userId != null && participants.value[existingIndex].userId == null) {
                participants.value.splice(existingIndex, 1, { ...participants.value[existingIndex], userId })
                resolveDisplayName(userId)
            }
            return
        }

        participants.value = [...participants.value, { id, isLocal, name: null, userId: userId ?? null }]
        if (userId != null) resolveDisplayName(userId)
    }

    // SCRUM-334: a tile's label comes from our server, keyed by the participant key the server
    // minted into that participant's token -- never the provider-side name, which a Daily
    // participant can change from their own browser (so an "Anonymous" member could otherwise label
    // themselves as a counsellor). The local tile is always "You" (VideoCallPanel.vue), so never
    // looked up. A tile stays on the "Participant" fallback until this resolves, or if it can't.
    function resolveDisplayName(key) {
        if (key === ownUserId) return

        let lookup = displayNames.get(key)
        if (!lookup) {
            lookup = axios.get(route('sessions.video.participants.show', {
                sessionId: session.value.id,
                participantKey: key,
            }))
                .then((res) => res.data?.displayName ?? null)
                .catch((err) => {
                    // A transient failure is retried by this person's next tile (e.g. a reconnect);
                    // a refusal stands until the next join.
                    if (!err.response || err.response.status >= 500 || err.response.status === 429) {
                        if (displayNames.get(key) === lookup) displayNames.delete(key)
                    }
                    return null
                })
            displayNames.set(key, lookup)
        }

        lookup.then((name) => {
            if (!name || displayNames.get(key) !== lookup) return
            participants.value = participants.value.map((p) => (p.userId === key && !p.isLocal ? { ...p, name } : p))
        })
    }

    // Local-only: drops a tile once the provider's own SDK reports that participant gone (a normal
    // leave, or the provider-side effect of removeParticipant()'s own eject/DeleteAttendee call
    // reaching this browser). Deliberately named apart from the exported removeParticipant()
    // action below, which is the COUNSELLOR's own request to eject someone else server-side --
    // this one only ever reacts to a tile already being gone.
    function dropParticipantTile(id) {
        const leaving = participants.value.find((p) => p.id === id)
        participants.value = participants.value.filter((p) => p.id !== id)
        forgetRemoteMedia(id)

        // TT-3.2f-j/SCRUM-327: someone who left can't be granted or revoked -- drop them from the
        // counsellor's queue too, unless another tile (a reconnect) still carries the same user.
        const userId = leaving?.userId
        if (userId == null || participants.value.some((p) => p.userId === userId)) return
        raisedHandUserIds.value = raisedHandUserIds.value.filter((uid) => uid !== userId)
        speakerUserIds.value = speakerUserIds.value.filter((uid) => uid !== userId)
    }

    // TT-3.3b/SCRUM-459: bumped whenever a client is built (buildCallbacks()) or taken out of use
    // (discardClient()). Each client's callbacks only act while their own generation is current,
    // so a client being torn down -- or one that leaked -- can never fire into the call that
    // replaced it.
    let clientGeneration = 0

    function buildCallbacks() {
        const generation = ++clientGeneration
        const live = (callback) => (...args) => (generation === clientGeneration ? callback(...args) : undefined)

        return {
            onParticipantJoined: live((participant) => {
                // TT-3.3c/SCRUM-460: the provider doesn't keep data messages for people who join
                // later -- tell a newcomer our video is paused for the connection.
                const isNew = !participants.value.some((p) => p.id === participant.id)
                upsertParticipant(participant)
                if (isNew && !participant.isLocal && videoPausedForNetwork.value) sendNetworkPause(true)
            }),
            onParticipantLeft: live(dropParticipantTile),
            // Purely a signal -- VideoCallPanel.vue owns the actual <video> element refs and
            // decides when to (re-)call attachVideo(); there's no state to track here.
            onVideoAvailable: () => {},
            onConnectionQualityChanged: live((quality) => {
                setConnectionQuality(normalizeQuality(quality))
                scheduleAutoVideo()
            }),
            onRemoteVideoChanged: live((id, isOn) => {
                if (id === 'local') return
                remoteMedia.value = { ...remoteMedia.value, [id]: remoteVideoChanged(remoteMedia.value[id], !!isOn) }
            }),
            // Only for someone actually in the call -- the label goes on their existing tile.
            onRemoteNetworkPause: live((id, paused) => {
                if (typeof paused !== 'boolean' || !participants.value.some((p) => p.id === id && !p.isLocal)) return
                remoteMedia.value = { ...remoteMedia.value, [id]: remoteNetworkPauseChanged(remoteMedia.value[id], paused) }
            }),
            // TT-3.3b/SCRUM-459: only while connected -- once this composable has taken over (a
            // drop it is rejoining from, or a call that's over), a late SDK event must not flip it.
            onReconnecting: live((isReconnecting) => {
                if (status.value !== 'connected') return
                reconnecting.value = !!isReconnecting
            }),
            onError: live((error) => {
                lastError.value = error?.message || error?.errorMsg || 'An unexpected video error occurred.'
            }),
            onDisconnected: live(handleDisconnected),
        }
    }

    // Takes the current client out of use (its callbacks go quiet) and hands it back to be destroyed.
    function discardClient() {
        const stale = client
        client = null
        clientGeneration++
        return stale
    }

    // Rejects with a 'TimeoutError' if `promise` hasn't settled within `ms` (0: no limit). The
    // original promise is left to settle on its own -- its late rejection is swallowed.
    function withinTime(promise, ms) {
        if (!(ms > 0)) return promise

        promise.catch(() => {})
        let timer
        const timeout = new Promise((resolve, reject) => {
            timer = setTimeout(() => {
                const error = new Error('The video service took too long to connect.')
                error.name = 'TimeoutError'
                reject(error)
            }, ms)
        })

        return Promise.race([promise, timeout]).finally(() => clearTimeout(timer))
    }

    async function destroyQuietly(staleClient) {
        if (!staleClient) return
        try {
            await staleClient.destroy()
        } catch (err) {
            // Best-effort, same reasoning as leave()/end()'s own provider-call handling -- the
            // client is already gone from this composable's point of view either way.
        }
    }

    async function loadClient() {
        // SCRUM-458: the provider-less fake, local/testing only (the server refuses it elsewhere).
        if (usePage().props.videoProvider === 'fake') {
            const { default: createFakeVideoClient } = await import('@/Video/FakeVideoClient')
            return createFakeVideoClient(buildCallbacks())
        }

        if (usePage().props.videoProvider === 'chime') {
            const { default: createChimeVideoClient } = await import('@/Video/ChimeVideoClient')
            return createChimeVideoClient(buildCallbacks())
        }

        const { default: createDailyVideoClient } = await import('@/Video/DailyVideoClient')
        return createDailyVideoClient(buildCallbacks())
    }

    // Incremented on every join() call and on cancel() -- lets an in-flight join() notice its
    // caller has since gone away (VideoCallPanel.vue unmounted mid-connect, e.g. the session
    // stopped being joinable while the camera/mic permission prompt was still up) and tear down
    // whatever it already started instead of assigning `client`/flipping to 'connected' for a
    // component nobody is reading anymore -- a real resource leak (open camera/mic, live
    // provider connection) found in review, not just a stale-UI cosmetic issue.
    let joinToken = 0

    // TT-3.3b/SCRUM-459: the VideoSession (call epoch) this browser last joined, from the join
    // response -- an automatic rejoin names it, so the server can refuse to put someone back into a
    // call that has ended or that they were taken out of (JoinVideoSessionAction).
    let videoSessionId = null

    // The public join: the panel's first join and every "try again". TT-3.3b/SCRUM-459: a user's own
    // join supersedes any automatic rejoin still pending, so the two never race -- and, being the
    // user's own choice, it is never marked as a rejoin (exactly as allowed as it always was).
    async function join() {
        abortReconnect()
        await attemptJoin()
    }

    // TT-3.3b/SCRUM-459: the join itself, shared by join() and the automatic rejoin -- which needs
    // to know how an attempt ended to decide whether another is worth making:
    //   { outcome: 'connected' | 'failed' | 'cancelled' | 'skipped',
    //     stage: 'http' | 'media', httpStatus, consentRequired, errorName }
    // stage 'http' is the sessions.video.join call failing (httpStatus null: no response at all --
    // the network, or `timeoutMs` running out); 'media' is the provider SDK failing after it
    // succeeded (errorName says why, e.g. a refused camera, or 'TimeoutError'). `timeoutMs` bounds
    // the whole attempt -- the join call and the provider connect after it.
    async function attemptJoin({ rejoin = false, timeoutMs = 0 } = {}) {
        if (!session.value?.id) return { outcome: 'skipped' }
        if (status.value === 'connecting' || status.value === 'connected') return { outcome: 'skipped' }

        const deadline = timeoutMs > 0 ? Date.now() + timeoutMs : 0
        const token = ++joinToken
        status.value = 'connecting'
        lastError.value = ''
        removedByCounsellor.value = false
        // Fresh labels on every (re)join -- e.g. a group made anonymous since the last one.
        displayNames = new Map()

        let credentials
        try {
            const response = await axios.post(
                route('sessions.video.join', session.value.id),
                rejoin ? { rejoin: true, videoSessionId } : {},
                timeoutMs > 0 ? { timeout: timeoutMs } : {},
            )
            credentials = response.data
        } catch (err) {
            if (token !== joinToken) return { outcome: 'cancelled' }

            const failure = {
                outcome: 'failed',
                stage: 'http',
                httpStatus: err.response?.status ?? null,
                consentRequired: !!err.response?.data?.videoConsentRequired,
            }

            // TT-3.3b/SCRUM-459: the server refused an automatic rejoin -- the call ended, or this
            // person was taken out of it, while this browser was cut off and missed the broadcast.
            const refused = err.response?.data?.rejoinRefused
            if (refused === 'ended' || refused === 'removed') {
                stopListeningForStatusChanges()
                status.value = refused
                return failure
            }

            if (err.response?.status === 402) {
                status.value = 'payment_required'
                lastError.value = err.response?.data?.message || 'Payment is required to access video for this session.'
                return failure
            }

            // TT-3.1e-f/SCRUM-285: a dedicated flag from VideoConsentRequiredException, not a
            // string match on the message -- lets VideoCallPanel.vue show a specific "guardian
            // consent needed" banner instead of the generic error+retry UI, which would be
            // misleading here since retrying does nothing until a guardian actually acts.
            if (err.response?.data?.videoConsentRequired) {
                status.value = 'consent_required'
                lastError.value = err.response?.data?.message || 'Guardian video consent is required before this account can join video for this session.'
                return failure
            }

            lastError.value = err.response?.data?.message || 'Unable to join video. Please try again shortly.'
            status.value = 'error'
            return failure
        }

        if (token !== joinToken) {
            // Cancelled while the join HTTP call was in flight -- a VideoSession/room now
            // exists server-side with nobody about to join it locally, so notify the backend of
            // the abandoned join the same way a normal leave() would.
            axios.post(route('sessions.video.leave', session.value.id)).catch(() => {})
            return { outcome: 'cancelled' }
        }

        videoSessionId = credentials.videoSessionId ?? null

        // TT-3.2f-j/SCRUM-327 review finding: subscribed as soon as the join response arrives, not
        // after client.join()'s media setup (seconds, longer behind a camera prompt) -- a hand
        // raised or grant made in that window would otherwise never reach a counsellor's lists.
        applyJoinState(credentials.participant)
        listenForStatusChanges()

        try {
            client = await loadClient()

            if (token !== joinToken) {
                await destroyQuietly(discardClient())
                return { outcome: 'cancelled' }
            }

            // TT-3.3b/SCRUM-459: a rejoin's provider connect gets only what is left of its time --
            // running out is a media-stage failure like any other (the catch destroys the client).
            // TT-3.3c/SCRUM-460: devices start as applyJoinState() decided (joinMediaState()) -- an
            // automatic rejoin never switches on a camera or mic the person had off, and keeps the
            // app's pause / "audio only" rather than flashing the camera on.
            const joinOptions = { startVideoOff: !isCameraOn.value, startAudioOff: isMuted.value }
            await withinTime(client.join(credentials, joinOptions), deadline ? Math.max(1000, deadline - Date.now()) : 0)

            if (token !== joinToken) {
                await destroyQuietly(discardClient())
                return { outcome: 'cancelled' }
            }

            resetQualityAnnouncements()
            status.value = 'connected'
            await reapplyVideoMode()
            return { outcome: 'connected' }
        } catch (err) {
            if (token !== joinToken) return { outcome: 'cancelled' }

            stopIdleTracking()
            stopAutoVideo()
            // TT-3.3b/SCRUM-459: not while an automatic rejoin is running -- an "ended"/"removed"
            // broadcast arriving before its next attempt must still be seen (reconnect() lets go of
            // them itself when it gives up on a refusal).
            if (!rejoin) stopListeningForStatusChanges()

            // TT-3.3b/SCRUM-459 review: destroyed, not just dropped -- daily-js refuses to create a
            // second call object while one exists, so a leaked one failed every later attempt.
            await destroyQuietly(discardClient())

            // The join call opened a participant row for this attempt; nobody is in the call on
            // it, so close it (best effort) rather than leave it counted as "in the call" -- awaited,
            // so the next attempt's join can't overtake it and have its own row closed instead.
            await axios.post(route('sessions.video.leave', session.value.id)).catch(() => {})

            if (token !== joinToken) return { outcome: 'cancelled' }

            // The join-video HTTP call already succeeded (a VideoSession/room exists) -- this is
            // strictly the SDK/media layer failing (camera/mic permission denial, a connection
            // failure to the provider's own servers), so it must never be confused with the
            // payment-gate/authorization failures caught above.
            lastError.value = 'Could not connect to the video call. Check your camera/microphone permissions and try again.'
            status.value = 'error'
            return { outcome: 'failed', stage: 'media', httpStatus: null, consentRequired: false, errorName: err?.name ?? null }
        }
    }

    // Called instead of leave() when a caller (VideoCallPanel.vue) goes away without a connected
    // call -- leave() assumes a connected client/room to notify the backend about in the normal
    // way; this instead invalidates whichever join() is in flight so IT handles its own teardown
    // once its next await resolves (see the token checks above). Safe in any state.
    async function cancel() {
        joinToken++
        // TT-3.3b/SCRUM-459: leaving the page mid-reconnect clears every pending timer/listener.
        // Deliberately no sessions.video.leave for a call waiting to rejoin: a drop is never a leave
        // (the row the drop left open stays open, as it always has -- SCRUM-330).
        abortReconnect()
        clearTimeout(announceTimer)
        // Not inside teardownClient()'s `if (client)` only -- join() now subscribes before a client
        // exists, so a cancel during loadClient() must unsubscribe too.
        stopIdleTracking()
        stopAutoVideo()
        clearChannelWatch()
        stopListeningForStatusChanges()
        if (client) await teardownClient()
    }

    // TT-3.1d/SCRUM-277: fired when the active provider client's own SDK reports the LOCAL
    // connection dropped out from under us (not a leave()/end() we called ourselves -- both
    // clients gate this via their own intentionalTeardown flag). Deliberately never touches
    // Session.status or calls sessions.video.leave/end -- a transient disconnect is not a leave,
    // the participant is still "in" the session's video from the backend's point of view (the
    // VideoSession epoch stays open; nothing here ends it), they just need fresh provider
    // credentials to reconnect to it. Rejoining reuses join()'s own code path (attemptJoin() --
    // same payment-gate/availability/consent checks, same VideoSession epoch reuse via
    // JoinVideoSessionAction's own find-or-create logic) rather than any provider-specific
    // "resume" API, so this works identically for both providers without knowing which is active.
    //
    // TT-3.3b/SCRUM-459: only a stop the client classified as recoverable (connectionPolicy.js's
    // allow-list) is rejoined -- and then by reconnect() below, with backoff, not immediately.
    // Anything else (ended, removed, joined elsewhere, an unrecognised reason) is terminal: the
    // call lands in the matching existing state and is never rejoined.
    async function handleDisconnected(rawStop) {
        if (status.value !== 'connected') return

        const stop = normalizeStop(rawStop)

        // Security-review finding (2026-09-11): captured here, the same way join() captures its
        // own token, so cancel() firing anywhere in this function's own async gaps (most
        // importantly while the stale client's destroy() is pending, the one window where `client`
        // is already null and cancel()'s `if (client) …` branch has nothing left to tear down) is
        // actually noticed before the reconnect's HTTP call goes out -- without this, a
        // component unmounting during exactly that window left a live camera/mic feed and
        // provider connection open for nobody, the same resource-leak class join()'s own token
        // check already guards against for a first-time join.
        //
        // Review finding (2026-09-11): leave()/end() now bump this SAME counter (see below), so
        // a concurrent explicit "leave call" click or the other participant ending the call
        // invalidates an in-flight reconnect exactly the same way an unmount does -- without
        // that, a user who clicked "leave" while a disconnect was also being handled could see
        // this reconnect silently resurrect the call after they'd already left it.
        const token = ++joinToken

        const staleClient = discardClient()
        participants.value = []
        remoteMedia.value = {}
        setConnectionQuality('unknown')
        // TT-3.2f-j/SCRUM-327 review finding: no activity pings for a call this browser isn't in --
        // and if the rejoin fails, nothing else would ever stop the timer. The rejoin's
        // applyJoinState() resets mute/camera/grant state from its own response.
        stopIdleTracking()
        // TT-3.3c/SCRUM-460: no pause/restore against a client that's gone. Whether video stays
        // paused / audio only is kept for the rejoin (see applyJoinState()).
        stopAutoVideo()

        if (!stop.recoverable) {
            // Nothing left to listen for -- this call is over from this browser's point of view.
            stopListeningForStatusChanges()
            reconnecting.value = false
            lastError.value = stop.message
            status.value = stop.outcome
            await destroyQuietly(staleClient)
            return
        }

        reconnecting.value = true
        // Review finding (2026-09-11): deliberately does NOT stopListeningForStatusChanges() here
        // (unlike leave()/end()/teardownClient()) -- the whole point of staying subscribed
        // through the reconnect gap is so an "ended" broadcast arriving DURING it (e.g. the other
        // participant ending the call at the same moment this side disconnected) is still seen.
        // listenForStatusChanges()'s own handler below reacts to that by invalidating this same
        // token and stopping reconnect() (see abortReconnect()).

        // 'idle', not 'connecting' -- attemptJoin()'s own re-entry guard only blocks 'connecting'/
        // 'connected', so this must clear 'connected' without tripping that guard on a rejoin.
        status.value = 'idle'

        await destroyQuietly(staleClient)

        if (token !== joinToken) {
            // Cancelled while tearing down the stale client -- do not proceed to reconnect for a
            // caller that's already gone.
            reconnecting.value = false
            return
        }

        await reconnect()
    }

    // TT-3.3b/SCRUM-459: the automatic rejoin after a recoverable stop -- connectionPolicy.js's
    // RECONNECT_DELAYS_MS (2s, 5s, 10s) within RECONNECT_WINDOW_MS (60s), each attempt waiting for
    // the browser to be online first, each a normal attemptJoin() through sessions.video.join --
    // marked as a rejoin of the same call, and given only the time left in the window. Whether a
    // failed attempt is retried is shouldRetryJoin()'s call (network/5xx/409; a provider connect
    // failure once more at most; never a device error). A payment/consent/validation refusal, or
    // the server refusing the rejoin, stays in the state attemptJoin() already put it in (e.g.
    // consent_required), so a revoked consent never gets media back. Nothing is ever sent to the
    // server as a leave because of a drop.
    //
    // Stopped at once by anything that ends the call here (cancel(), leave(), end(), the ended/
    // removed broadcasts, the user's own join()) via abortReconnect(): each await below re-checks
    // its run id, so a stale attempt can never bring the call back.
    let reconnectRun = 0
    let stopReconnectWait = null

    async function reconnect() {
        const run = ++reconnectRun
        const startedAt = Date.now()
        const isCurrent = () => run === reconnectRun
        const timeLeft = () => RECONNECT_WINDOW_MS - (Date.now() - startedAt)
        let mediaRetriesUsed = 0

        for (let attempt = 0; ; attempt++) {
            const delay = reconnectDelay(attempt, Date.now() - startedAt)
            if (delay === null) break

            if (!(await waitForReconnect(delay, false)) || !isCurrent()) return

            const online = timeLeft() > 0 && await waitForReconnect(timeLeft(), true)
            if (!isCurrent()) return
            if (!online) break

            reconnectAttempt.value = attempt + 1
            const result = await attemptJoin({ rejoin: true, timeoutMs: Math.max(1000, timeLeft()) })
            if (!isCurrent()) return

            if (result.outcome === 'connected') {
                abortReconnect()
                return
            }

            // A join that never ran (or was cancelled by something that didn't stop this run):
            // there is nothing more to try automatically.
            if (result.outcome !== 'failed') break

            if (status.value === 'error' && shouldRetryJoin({ ...result, mediaRetriesUsed })) {
                if (result.stage === 'media') mediaRetriesUsed++
                // Back to "Reconnecting…" until the next attempt.
                status.value = 'idle'
                continue
            }

            // A provider connect that keeps failing (its one retry used up) isn't a refusal or the
            // person's devices: like running out of time, it ends in "We couldn't reconnect" with
            // the text-chat hint, not the camera-permission message attemptJoin() left.
            if (result.stage === 'media' && !DEVICE_ERROR_NAMES.includes(result.errorName)) break

            // A refusal (or a device the person has to fix): it keeps whatever state attemptJoin() left, and this browser is no
            // longer in the call, so stop listening for it.
            abortReconnect()
            stopListeningForStatusChanges()
            return
        }

        abortReconnect()
        lastError.value = ''
        status.value = 'reconnect_failed'
    }

    // Resolves true after `ms` -- or, with untilOnline, as soon as the browser is online (at once
    // if it already is), false if it isn't within `ms`. Resolves false at once if reconnecting is
    // stopped meanwhile. Only one wait is ever pending.
    function waitForReconnect(ms, untilOnline) {
        if (untilOnline && (typeof navigator === 'undefined' || navigator.onLine !== false)) {
            return Promise.resolve(true)
        }

        return new Promise((resolve) => {
            const settle = (value) => {
                clearTimeout(timer)
                if (untilOnline) window.removeEventListener('online', onOnline)
                if (stopReconnectWait === settle) stopReconnectWait = null
                resolve(value)
            }
            const onOnline = () => settle(true)
            const timer = setTimeout(() => settle(!untilOnline), ms)
            if (untilOnline) window.addEventListener('online', onOnline)
            stopReconnectWait = settle
        })
    }

    // Ends any automatic rejoin: its pending wait resolves false (clearing its own timer/listener),
    // its run id goes stale, and the "Reconnecting…" state is cleared -- whoever calls this sets the
    // status that replaces it.
    function abortReconnect() {
        reconnectRun++
        stopReconnectWait?.(false)
        reconnecting.value = false
        reconnectAttempt.value = 0
    }

    // TT-3.3b/SCRUM-459: screen-reader announcements (VideoCallPanel.vue's live region), on
    // meaningful changes only -- the three watchers below. Quality goes through connectionPolicy.js's
    // qualityAnnouncement() (nothing for unknown, a cooldown except for 'bad'); a drop and a
    // reconnect are said once each; every status a call can land in is said from one map.
    let announcedQuality = QUALITY_GOOD
    let qualityAnnouncedAt = -Infinity

    function resetQualityAnnouncements() {
        announcedQuality = QUALITY_GOOD
        qualityAnnouncedAt = -Infinity
    }

    // Cleared first, and set a moment later, so a live region re-reads a message identical to the
    // last one (screen readers can miss a clear-and-set within one render). A newer message
    // replaces one still pending.
    const ANNOUNCE_DELAY_MS = 100
    let announceTimer = null

    function announce(message) {
        clearTimeout(announceTimer)
        announcement.value = ''
        announceTimer = setTimeout(() => {
            announceTimer = null
            announcement.value = message
        }, ANNOUNCE_DELAY_MS)
    }

    const STATUS_ANNOUNCEMENTS = {
        ended: () => 'The video call ended.',
        removed: () => (removedByCounsellor.value ? 'A counsellor removed you from this video call.' : 'You were taken out of this video call.'),
        reconnect_failed: () => 'We couldn\'t reconnect the video call.',
        payment_required: () => lastError.value,
        consent_required: () => lastError.value,
        error: () => lastError.value,
    }

    watch(connectionQuality, (next) => {
        if (status.value !== 'connected') return
        const now = Date.now()
        const message = qualityAnnouncement({ announced: announcedQuality, next, sinceLastMs: now - qualityAnnouncedAt })
        if (!message) return
        announcedQuality = next
        qualityAnnouncedAt = now
        announce(message)
    })

    // One watcher for both, so where a reconnect ends up is said once, whichever changed last.
    watch([status, reconnecting], ([next, isReconnecting], [previous, wasReconnecting]) => {
        if (isReconnecting && !wasReconnecting) return announce('Video connection lost. Reconnecting…')
        // A failed attempt between automatic rejoins isn't news -- only where the call lands is.
        if (isReconnecting) return
        if (wasReconnecting && next === 'connected') return announce('Video reconnected.')
        if (next === previous && !wasReconnecting) return

        const message = STATUS_ANNOUNCEMENTS[next]?.()
        if (message) announce(message)
    })

    async function leave() {
        if (!session.value?.id) return

        // Review finding (2026-09-11): invalidates any in-flight join()/handleDisconnected()
        // reconnect attempt -- without this, a disconnect racing this explicit "leave call"
        // click could complete its own automatic rejoin afterward and silently resurrect a call
        // the user just left.
        joinToken++
        abortReconnect()
        stopAutoVideo()

        try {
            await axios.post(route('sessions.video.leave', session.value.id))
        } catch (err) {
            // Best-effort -- the local call still tears down below regardless of whether the
            // backend accepted the leave notification (e.g. a dropped connection getting here at
            // all already means the network is unreliable).
        }

        await teardownClient()
        status.value = 'ended'
    }

    // Review finding (2026-09-11): no UI in this ticket calls end() -- VideoCallPanel.vue only
    // ever destructures leave(). Deliberate, not an oversight: TT-3.1c's own scope is a join/leave
    // flow (see the ticket's own text), and "end the call for everyone" is a materially different,
    // more consequential action (it also ends the room for whoever this user is calling with) that
    // deserves its own explicit UI treatment -- deferred rather than bolted on as a second, easily
    // mis-clicked button next to "leave call". Exposed here now so that follow-up ticket only needs
    // a UI, not a new composable method.
    async function end() {
        if (!session.value?.id) return

        // Same reasoning as leave()'s own joinToken bump above.
        joinToken++
        abortReconnect()
        stopAutoVideo()

        try {
            await axios.post(route('sessions.video.end', session.value.id))
        } catch (err) {
            // Best-effort, same reasoning as leave() above.
        }

        await teardownClient()
        status.value = 'ended'
    }

    // TT-3.2c/SCRUM-310: a counsellor's own "remove participant" action against someone ELSE in a
    // GroupTherapy call -- the backend re-authorizes this on every call regardless (this is purely
    // the HTTP call; VideoCallPanel.vue only ever shows the control when isCounsellor is true).
    // Deliberately does nothing to local state here -- the acting counsellor's own tile list
    // updates the normal way, via the provider's own participant-left callback once the eject/
    // DeleteAttendee call actually takes effect; the removed user's own experience is handled
    // entirely by listenForStatusChanges()'s own participant-removed handler below.
    async function removeParticipant(targetUserId) {
        if (!session.value?.id) return

        // SCRUM-315: the response says whether the video service confirmed the eject.
        const res = await axios.post(route('sessions.video.participants.remove', {
            sessionId: session.value.id,
            participantKey: targetUserId,
        }))

        return res.data
    }

    // TT-3.2f-j/SCRUM-327: every join starts fresh (a reconnect included -- the backend mints a new,
    // receive-only token for a member's new join row, so any earlier grant no longer applies).
    //
    // On a reconnect the counsellor lists are MERGED with the response's snapshot: the listeners
    // stayed subscribed through the gap, so the live lists may already hold changes newer than the
    // snapshot. Any other join (first join, or a retry after a failure, when nothing was listening)
    // takes the snapshot as-is. Known gap: someone who leaves during a counsellor's own reconnect
    // keeps a list entry (their tile never comes back to be dropped) until it's acted on.
    function applyJoinState(state) {
        capabilities.value = { ...defaultCapabilities(), ...(state ?? {}) }
        permissionGeneration++
        applyJoinMediaState()
        handRaised.value = false
        speakingNotice.value = ''
        mediaError.value = ''

        ownUserId = state?.key ?? null
        const snapshotHands = capabilities.value.canModerate ? (state?.raisedHandKeys ?? []) : []
        const snapshotSpeakers = capabilities.value.canModerate ? (state?.speakerKeys ?? []) : []
        const merge = reconnecting.value && capabilities.value.canModerate

        raisedHandUserIds.value = merge ? [...new Set([...raisedHandUserIds.value, ...snapshotHands])] : snapshotHands
        speakerUserIds.value = merge ? [...new Set([...speakerUserIds.value, ...snapshotSpeakers])] : snapshotSpeakers

        stopIdleTracking()
    }

    // TT-3.3c/SCRUM-460: the camera/mic state a join starts with -- audioOnlyPolicy.js's
    // joinMediaState(). A first join (or the person's own "try again") starts fresh. An automatic
    // rejoin (reconnecting) is the same call: it keeps the person's own camera-off and mute, the app's
    // pause (while video is still allowed), "audio only" and the bounce count.
    function applyJoinMediaState() {
        const rejoin = reconnecting.value
        const next = joinMediaState({
            rejoin,
            capabilities: capabilities.value,
            previous: {
                cameraWanted: cameraWanted.value,
                mutedByChoice,
                muted: isMuted.value,
                pausedByApp: videoPausedForNetwork.value,
                audioOnly: audioOnly.value,
            },
        })

        isCameraOn.value = next.cameraOn
        cameraWanted.value = next.cameraWanted
        isMuted.value = next.muted
        mutedByChoice = next.mutedByChoice
        videoPausedForNetwork.value = next.pausedByApp
        audioOnly.value = next.audioOnly
        if (!rejoin) autoRestoresUsed = 0
        if (!rejoin || !isVideoAllowed(capabilities.value)) resumeCameraAfterAudioOnly = false
        autoPauseSuspended = false
        clearChannelWatch()
        if (next.pausedByApp) startChannelWatch()
    }

    // TT-3.3c/SCRUM-460: once a rejoin is connected, put back what applyJoinMediaState() kept: the new
    // client receives everyone's video by default, and the others saw our old tile go.
    async function reapplyVideoMode() {
        const active = client
        if (!active) return
        if (audioOnly.value) await Promise.resolve(active.setReceiveVideo?.(false)).catch(() => {})
        if (active !== client) return
        if (videoPausedForNetwork.value) sendNetworkPause(true)
        scheduleAutoVideo()
    }

    // Asks to speak / withdraws the ask. The local flag is set from the response, not only the
    // broadcast, so the button responds even if the websocket is slow; the broadcast agrees.
    async function raiseHand() {
        if (!session.value?.id) return
        await axios.post(route('sessions.video.hand.raise', session.value.id))
        handRaised.value = true
    }

    async function lowerHand() {
        if (!session.value?.id) return
        await axios.post(route('sessions.video.hand.lower', session.value.id))
        handRaised.value = false
    }

    // Counsellor actions. Like removeParticipant(), no error handling here -- the caller shows the
    // backend's own message. Local lists update from the broadcasts, the single source of truth.
    async function grantSpeaking(targetUserId) {
        if (!session.value?.id) return
        await axios.post(route('sessions.video.participants.grant_speaking', {
            sessionId: session.value.id,
            participantKey: targetUserId,
        }))
    }

    async function revokeSpeaking(targetUserId) {
        if (!session.value?.id) return
        await axios.post(route('sessions.video.participants.revoke_speaking', {
            sessionId: session.value.id,
            participantKey: targetUserId,
        }))
    }

    // TT-3.2f-j/SCRUM-327 / TT-3.2f-h: a granted member's client pings while they're actually
    // sending (unmuted or camera on); the backend's idle sweep reverts a grant with no ping for
    // speakingGrantIdleTimeout seconds. Tracked locally too, so the member can be warned before it
    // happens rather than only told afterwards.
    const ACTIVITY_PING_INTERVAL_MS = 30000
    const IDLE_TICK_MS = 1000
    let idleTimer = null
    let lastActivityAt = 0
    let lastPingAt = 0

    function startIdleTracking() {
        stopIdleTracking()
        lastActivityAt = Date.now()
        lastPingAt = 0
        idleTick()
        idleTimer = setInterval(idleTick, IDLE_TICK_MS)
    }

    function stopIdleTracking() {
        if (idleTimer) clearInterval(idleTimer)
        idleTimer = null
        idleSecondsRemaining.value = null
    }

    function idleTick() {
        const now = Date.now()
        // TT-3.3c/SCRUM-460: the person's intent, not the camera's actual state -- a granted speaker
        // doesn't lose the floor because the app paused their camera for the connection.
        const isSending = !isMuted.value || cameraWanted.value

        if (isSending && now - lastPingAt >= ACTIVITY_PING_INTERVAL_MS) {
            lastPingAt = now
            // Best-effort: a missed ping can only bring the expiry closer, which this countdown
            // already reflects by not advancing lastActivityAt until the ping succeeds.
            axios.post(route('sessions.video.speaking_activity', session.value.id))
                .then(() => { lastActivityAt = Date.now() })
                .catch(() => {})
        }

        const elapsed = Math.floor((now - lastActivityAt) / 1000)
        idleSecondsRemaining.value = Math.max(0, capabilities.value.speakingGrantIdleTimeout - elapsed)
    }

    function applyOwnSpeakingPermission({ canSendAudio, canSendVideo, reason }) {
        // TT-3.3c/SCRUM-460: an automatic camera restore in flight checks this before leaving the
        // camera on (see autoRestoreVideo()).
        permissionGeneration++

        // SCRUM-329: a creator (who joined able to speak, never by a grant) is only ever muted when their guardian's
        // consent now lets them watch only -- the call is told it as a plain revoke (it mustn't learn they're under 18),
        // so the panel becomes the watching one here.
        if (!capabilities.value.receiveOnly && !canSendAudio) {
            Promise.resolve(client?.disableLocalMedia()).catch(() => {})
            capabilities.value = { ...capabilities.value, receiveOnly: true, canSendAudio: false, canSendVideo: false, canRaiseHand: false }
            isMuted.value = true
            cameraForcedOff()
            speakingNotice.value = 'You can now watch and listen only.'
            return
        }

        if (!capabilities.value.receiveOnly) return

        const wasGranted = holdsSpeakingGrant.value
        capabilities.value = { ...capabilities.value, canSendAudio, canSendVideo }
        handRaised.value = false

        if (canSendAudio) {
            // Granted: nothing is sent until the member chooses to unmute.
            isMuted.value = true
            cameraForcedOff()
            speakingNotice.value = ''
            startIdleTracking()
            return
        }

        // A provider-level "make sure they're muted" (RevokeSpeakingPermissionAction::muteAtProvider)
        // also broadcasts for someone who was only ever watching -- nothing to undo or explain then.
        if (!wasGranted) return

        Promise.resolve(client?.disableLocalMedia()).catch(() => {})
        isMuted.value = true
        cameraForcedOff()
        stopIdleTracking()
        speakingNotice.value = reason === 'IDLE_AUTO_EXPIRY'
            ? 'Your permission to speak ended because you stayed muted for a while. Raise your hand to ask again.'
            : 'Your permission to speak ended.'
    }

    async function teardownClient() {
        stopIdleTracking()
        stopAutoVideo()
        clearChannelWatch()
        stopListeningForStatusChanges()
        await destroyQuietly(discardClient())
        participants.value = []
        remoteMedia.value = {}
    }

    // TT-3.3c/SCRUM-460: the person's own mute -- an automatic rejoin keeps it (joinMediaState()).
    let mutedByChoice = false

    async function toggleMute() {
        await toggleMedia('mic', async (active) => {
            isMuted.value = await active.toggleMute()
            mutedByChoice = isMuted.value
        }, 'Could not use your microphone. Check your browser\'s microphone permission and try again.')
    }

    // TT-3.3c/SCRUM-460: the person's own choice -- it sets cameraWanted. Turning the camera on while
    // it's paused for the connection is "Turn video back on": the pause ends, everyone else's label
    // clears, and the app won't pause it again until the connection has been good.
    async function toggleCamera() {
        await toggleMedia('camera', async (active) => {
            const wasPaused = videoPausedForNetwork.value
            const on = await active.toggleCamera()
            isCameraOn.value = on
            cameraWanted.value = on
            // Only "Turn video back on" from a pause holds off the next pause -- a camera turned on
            // any other time is paused as usual if the connection turns bad (found in browser QA).
            if (on && wasPaused) autoPauseSuspended = true
            if (active !== client) return
            if (wasPaused) endNetworkPause()
            if (on && (wasPaused || announceCameraBack)) announce('Video is back on.')
        }, 'Could not use your camera. Check your browser\'s camera permission and try again.')
        announceCameraBack = false
        scheduleAutoVideo()
    }

    // TT-3.3c/SCRUM-460: one lock per device ('mic' | 'camera'), shared by the person's toggles and
    // every automatic/"audio only" camera change -- so two camera changes never interleave, while
    // muting always stays possible. Runs fn(client) with the client it started on; does nothing if
    // there is no client or that device is busy.
    async function withMediaLock(kind, fn) {
        const lock = kind === 'mic' ? micBusy : cameraBusy
        const active = client
        if (!active || lock.value) return undefined

        lock.value = true
        try {
            return await fn(active)
        } finally {
            lock.value = false
        }
    }

    async function toggleMedia(kind, toggle, failureMessage) {
        await withMediaLock(kind, async (active) => {
            mediaError.value = ''

            try {
                await toggle(active)
                if (holdsSpeakingGrant.value) idleTick()

                // A revoke landed while this toggle was in flight (its broadcast already ran
                // applyOwnSpeakingPermission()) -- never leave a device on that the hidden controls
                // can no longer turn off.
                if (capabilities.value.receiveOnly && !holdsSpeakingGrant.value) {
                    await Promise.resolve(client?.disableLocalMedia()).catch(() => {})
                    isMuted.value = true
                    cameraForcedOff()
                }
            } catch (err) {
                mediaError.value = failureMessage
            }
        })
    }

    // TT-3.3c/SCRUM-460: automatic audio only. The rules are audioOnlyPolicy.js's autoVideoDecision();
    // this only keeps its inputs, runs one timer for whatever it says is next, and does it. Re-asked
    // on every quality change, after each action, and after the person's own camera/mode changes.
    let qualityChangedAt = Date.now()
    let autoVideoTimer = null
    let autoRestoresUsed = 0
    // The person turned video back on themselves -- no automatic pause until the connection has been
    // good again (nextAutoPauseSuspended()).
    let autoPauseSuspended = false
    // Bumped by every capability change (join, grant, revoke, downgrade) -- see autoActionStillValid().
    let permissionGeneration = 0
    // Whether leaving "audio only" should offer the camera back (it was wanted on going in), and
    // whether the camera coming back from there should be announced.
    let resumeCameraAfterAudioOnly = false
    let announceCameraBack = false
    const AUTO_VIDEO_BUSY_RETRY_MS = 1000

    function setConnectionQuality(quality) {
        if (quality === connectionQuality.value) return
        connectionQuality.value = quality
        qualityChangedAt = Date.now()
        autoPauseSuspended = nextAutoPauseSuspended(autoPauseSuspended, quality)
    }

    // TT-3.3c/SCRUM-460: revokes and consent downgrades reach this browser over the session's Reverb
    // channel. If that connection dropped at any point while the app had video paused, a revoke may
    // have been missed, so the automatic restore is skipped (restoreBlocked()) -- the person's own
    // "Turn video back on" stays. Read from the pusher-js connection Echo wraps (the same object
    // useConnectionStatus.js watches): its state_change event, and its current state. With no Echo
    // connection at all there is nothing to watch, and nothing could have been missed meanwhile that
    // the call wasn't already missing -- treated as connected.
    let channelDroppedWhilePaused = false
    let channelWatch = null

    function echoConnection() {
        return window.Echo?.connector?.pusher?.connection ?? null
    }

    function channelConnected() {
        const connection = echoConnection()
        return connection ? connection.state === 'connected' : true
    }

    function startChannelWatch() {
        clearChannelWatch()
        channelDroppedWhilePaused = !channelConnected()
        const connection = echoConnection()
        if (!connection) return
        const handler = (states) => {
            if (states?.current !== 'connected') channelDroppedWhilePaused = true
        }
        connection.bind('state_change', handler)
        channelWatch = { connection, handler }
    }

    function clearChannelWatch() {
        channelWatch?.connection.unbind('state_change', channelWatch.handler)
        channelWatch = null
        channelDroppedWhilePaused = false
    }

    function autoVideoInputs() {
        return {
            quality: connectionQuality.value,
            qualityForMs: Date.now() - qualityChangedAt,
            cameraOn: isCameraOn.value,
            cameraWanted: cameraWanted.value,
            pausedByApp: videoPausedForNetwork.value,
            videoAllowed: isVideoAllowed(capabilities.value),
            audioOnly: audioOnly.value,
            restoresUsed: autoRestoresUsed,
            autoPauseSuspended,
            restoreBlocked: restoreBlocked({
                channelDroppedWhilePaused,
                channelConnected: channelConnected(),
                providerAllowsVideo: client?.providerAllowsVideo?.() ?? null,
            }),
        }
    }

    function stopAutoVideo() {
        clearTimeout(autoVideoTimer)
        autoVideoTimer = null
    }

    function scheduleAutoVideo(delayMs = null) {
        stopAutoVideo()
        if (status.value !== 'connected' || !client) return

        const decision = autoVideoDecision(autoVideoInputs())
        if (!decision.action) return
        autoVideoTimer = setTimeout(runAutoVideo, delayMs ?? decision.inMs)
    }

    async function runAutoVideo() {
        autoVideoTimer = null
        if (status.value !== 'connected' || !client) return
        // A camera change (the person's, or an earlier automatic one) is still running -- ask again
        // shortly. The mic is never waited on: muting is always possible.
        if (cameraBusy.value) return scheduleAutoVideo(AUTO_VIDEO_BUSY_RETRY_MS)

        const decision = autoVideoDecision(autoVideoInputs())
        if (!decision.action) return
        if (decision.inMs > 0) return scheduleAutoVideo()

        const done = decision.action === AUTO_PAUSE ? await autoPauseVideo() : await autoRestoreVideo()
        // A failed or dropped attempt waits for the next quality change rather than retrying in a loop.
        if (done) scheduleAutoVideo()
    }

    // The camera only -- the microphone is never touched. A capability change (or video no longer
    // being allowed) while the camera was turning off drops the result: no pause flag, no message to
    // the others, no announcement -- whatever caused the change has already set the camera state.
    // Returns whether the pause took.
    async function autoPauseVideo() {
        const generation = permissionGeneration
        if (!isVideoAllowed(capabilities.value)) return false

        const result = await withMediaLock('camera', async (active) => {
            try {
                const on = await active.setCamera(false)
                if (active !== client) return false
                isCameraOn.value = on
                const valid = autoActionStillValid({
                    action: AUTO_PAUSE,
                    generationBefore: generation,
                    generationAfter: permissionGeneration,
                    videoAllowed: isVideoAllowed(capabilities.value),
                })
                if (on || !valid) return false
                videoPausedForNetwork.value = true
                startChannelWatch()
                sendNetworkPause(true)
                announce('Video paused because of your connection. Audio continues.')
                return true
            } catch (err) {
                return false
            }
        })

        return result === true
    }

    // Only ever a camera the app paused, and only while video is still allowed and nothing blocks it
    // (restoreBlocked()) -- checked again after the device start, exactly like toggleMedia()'s revoke
    // race: a revoke or consent downgrade that landed meanwhile (applyOwnSpeakingPermission() bumps
    // permissionGeneration; Chime and the fake also cancel the start themselves via
    // disableLocalMedia()'s mediaGeneration) turns it straight back off. Never through the panel's
    // reveal warning: only a camera that was already on (so already past that warning) is ever
    // paused, and so restored. Returns whether it came back.
    async function autoRestoreVideo() {
        const generation = permissionGeneration
        if (!isVideoAllowed(capabilities.value)) return false

        const result = await withMediaLock('camera', async (active) => {
            try {
                const on = await active.setCamera(true)
                if (active !== client) return false

                const valid = autoActionStillValid({
                    action: AUTO_RESTORE,
                    generationBefore: generation,
                    generationAfter: permissionGeneration,
                    videoAllowed: isVideoAllowed(capabilities.value),
                    pausedByApp: videoPausedForNetwork.value,
                })
                if (!valid) {
                    if (on) await Promise.resolve(active.setCamera(false)).catch(() => {})
                    isCameraOn.value = false
                    return false
                }

                isCameraOn.value = on
                if (!on) return false
                autoRestoresUsed++
                endNetworkPause()
                announce('Video is back on.')
                return true
            } catch (err) {
                return false
            }
        })

        return result === true
    }

    function sendNetworkPause(paused) {
        try {
            client?.sendNetworkPause?.(paused)
        } catch (err) {
            // Best effort -- the others just see a plain "camera off" tile.
        }
    }

    // The app's pause is over (video back, the person's own choice, or forced off): the others' label
    // clears and the channel is no longer watched for it.
    function endNetworkPause() {
        if (!videoPausedForNetwork.value) return
        videoPausedForNetwork.value = false
        clearChannelWatch()
        sendNetworkPause(false)
    }

    // Any forced camera-off (a revoke, a consent downgrade, a grant that starts with it off): the
    // person's intent goes too, and so does any pause the app was holding -- it may not come back.
    function cameraForcedOff() {
        isCameraOn.value = false
        cameraWanted.value = false
        resumeCameraAfterAudioOnly = false
        endNetworkPause()
        stopAutoVideo()
    }

    function forgetRemoteMedia(id) {
        if (!(id in remoteMedia.value)) return
        const { [id]: _gone, ...rest } = remoteMedia.value
        remoteMedia.value = rest
    }

    // TT-3.3c/SCRUM-460: the person's own "audio only": their camera off and nobody else's video
    // received, to save data. Their camera choice is remembered and offered back on leaving; it is
    // only given up once the camera is confirmed off (a failure leaves everything as it was).
    async function enterAudioOnly() {
        if (audioOnly.value) return

        await withMediaLock('camera', async (active) => {
            const wanted = cameraWanted.value
            stopAutoVideo()
            // Set first, so no automatic pause/restore acts while this runs.
            audioOnly.value = true

            try {
                if (isCameraOn.value) isCameraOn.value = await active.setCamera(false)
            } catch (err) {
                isCameraOn.value = true
            }
            if (active !== client) return
            if (isCameraOn.value) {
                audioOnly.value = false
                mediaError.value = 'Could not turn your camera off. Please try again.'
                return
            }

            resumeCameraAfterAudioOnly = wanted && isVideoAllowed(capabilities.value)
            cameraWanted.value = false
            endNetworkPause()

            try {
                await active.setReceiveVideo(false)
            } catch (err) {
                // The provider refused -- the tiles still cover everyone's video.
            }
            announce('Audio only. Video is off.')
        })
        scheduleAutoVideo()
    }

    // Leaving "audio only": everyone's video comes back. Returns whether the person's camera was on
    // going in (and may still send) -- the panel then turns it on through its normal camera-on path,
    // which announces it; otherwise "Showing video again." is said here.
    async function leaveAudioOnly() {
        if (!audioOnly.value) return false

        const resume = await withMediaLock('camera', async (active) => {
            audioOnly.value = false
            const resumeCamera = resumeCameraAfterAudioOnly && isVideoAllowed(capabilities.value)
            resumeCameraAfterAudioOnly = false

            try {
                await active.setReceiveVideo(true)
            } catch (err) {
                // Best effort, as above.
            }
            return resumeCamera
        })

        if (resume) announceCameraBack = true
        else if (!audioOnly.value) announce('Showing video again.')
        scheduleAutoVideo()
        return !!resume
    }

    function attachVideo(participantId, element) {
        client?.attachVideo(participantId, element)
    }

    function listenForStatusChanges() {
        // A reconnect deliberately keeps the listeners from the first join (see
        // handleDisconnected()) -- subscribing again would stack a second copy of every handler.
        if (sessionChannel || !window.Echo) return

        // SCRUM-370: the session channel is shared with the chat and session badges -- this
        // handle adds and later removes only the call's own listeners (see sharedChannel.js).
        sessionChannel = acquireChannel(`sessions.${session.value.id}`)
        sessionChannel.listen('.video-session.status-changed', (data) => {
            // The room ended for everyone (e.g. the other participant ended it) -- react locally
            // even though we didn't call end() ourselves. Deliberately does NOT re-issue the
            // sessions.video.end HTTP call -- that's the ender's own action, already done.
            //
            // Review finding (2026-09-11): widened from `status.value === 'connected'` to "any
            // state except already-ended" -- this listener stays subscribed through
            // handleDisconnected()'s reconnect gap now (see its own comment), so an "ended"
            // broadcast arriving DURING a reconnect must still be acted on. Without the joinToken
            // bump here, a reconnect already past its own destroy()-await check could still go on
            // to call join() and silently open a brand-new VideoSession epoch/room instead of
            // surfacing "ended" -- JoinVideoSessionAction's own find-or-create only checks
            // whereNull('ended_at'), so it has no way to know a rejoin was already stale.
            if (data.status !== 'ended' || status.value === 'ended') return

            joinToken++
            // TT-3.3b/SCRUM-459: no automatic rejoin may outlive the call.
            abortReconnect()
            teardownClient()
            status.value = 'ended'
        })

        // TT-3.2c/SCRUM-310: fires for EVERY participant when anyone is removed, but only the
        // removed user's own browser (data.removedParticipantKey === ownUserId) needs to react -- every
        // other participant's tile list already updates on its own via the provider's normal
        // participant-left callback once the eject/DeleteAttendee call takes effect. Deliberately
        // its own `status.value = 'removed'`, never 'ended' -- see this composable's own top-of-
        // file status comment for why being removed must never look like the room simply ending.
        sessionChannel.listen('.video-session.participant-removed', (data) => {
            if (data.removedParticipantKey !== ownUserId || status.value === 'ended' || status.value === 'removed') return

            joinToken++
            abortReconnect()
            teardownClient()
            removedByCounsellor.value = true
            status.value = 'removed'
        })

        // TT-3.2f-j/SCRUM-327: hand-raise and speaking broadcasts carry ids only (anonymity-safe);
        // counsellors resolve names from the call's own tiles. Each viewer also watches for its
        // OWN id, to follow its hand and speaking state.
        sessionChannel.listen('.video-session.hand-raised', (data) => {
            if (data.participantKey === ownUserId) handRaised.value = true
            if (capabilities.value.canModerate && !raisedHandUserIds.value.includes(data.participantKey)) {
                raisedHandUserIds.value = [...raisedHandUserIds.value, data.participantKey]
            }
        })

        sessionChannel.listen('.video-session.hand-lowered', (data) => {
            if (data.participantKey === ownUserId) handRaised.value = false
            if (capabilities.value.canModerate) {
                raisedHandUserIds.value = raisedHandUserIds.value.filter((uid) => uid !== data.participantKey)
            }
        })

        sessionChannel.listen('.video-session.speaking-permission-changed', (data) => {
            if (data.participantKey === ownUserId) applyOwnSpeakingPermission(data)

            if (!capabilities.value.canModerate) return
            speakerUserIds.value = speakerUserIds.value.filter((uid) => uid !== data.participantKey)
            if (data.canSendAudio) speakerUserIds.value = [...speakerUserIds.value, data.participantKey]
        })
    }

    function stopListeningForStatusChanges() {
        sessionChannel?.release()
        sessionChannel = null
    }

    return {
        status, participants, isMuted, isCameraOn, connectionQuality, lastError, reconnecting,
        reconnectAttempt, announcement, removedByCounsellor, capabilities, holdsSpeakingGrant, handRaised, raisedHandUserIds, speakerUserIds, speakingNotice,
        idleSecondsRemaining, micBusy, cameraBusy, mediaError,
        cameraWanted, videoPausedForNetwork, audioOnly, remoteMedia, videoAllowed,
        join, leave, end, removeParticipant, cancel, toggleMute, toggleCamera, attachVideo,
        raiseHand, lowerHand, grantSpeaking, revokeSpeaking, enterAudioOnly, leaveAudioOnly,
    }
}
