import { computed, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'
import axios from 'axios'

// TT-3.1c/SCRUM-276: one composable, two interchangeable provider clients (Video/DailyVideoClient.js,
// Video/ChimeVideoClient.js) -- which one loads is decided entirely by the `videoProvider` Inertia
// shared prop (config('video.provider'), see HandleInertiaRequestsV2::share()), a deployment-level
// choice, never a runtime switch (mirrors TT-3.1a's own backend VideoServiceProvider binding).
//
// Deliberately NOT a formal JS interface/abstract class -- this codebase has no such precedent in
// resources/js, and there are permanently only ever two implementations chosen once. Both client
// files must implement this exact factory shape instead, documented here rather than enforced by
// the language (mirrors useConnectionStatus.js's own established idiom of injecting callbacks at
// construction time, not the backend's PHP `interface VideoProviderInterface` -- PHP needing a
// real interface for container-bound DI doesn't imply an equivalent formalism is warranted here):
//
//   export default function createXVideoClient(callbacks) => {
//     join(credentials): Promise<void>       -- credentials is this provider's own raw shape,
//                                                exactly POST sessions.video.join's JSON response
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
//     disableLocalMedia(): void|Promise      -- TT-3.2f-j/SCRUM-327: stop sending audio+video
//                                                (a revoked speaking permission). join() reads
//                                                credentials.participant's canSendAudio/
//                                                canSendVideo to decide which devices to open.
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
//     onParticipantJoined({ id, isLocal, name, userId })  -- name may be null (Chime has no
//                                                     display-name field at all -- see
//                                                     ChimeVideoClient.js). userId (TT-3.2c/
//                                                     SCRUM-310) is our OWN backend user id, never
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
//     onConnectionQualityChanged(quality)          -- 'good' | 'poor' | 'unknown'
//     onError(error)                               -- a reportable problem that does NOT mean
//                                                     the call itself ended (e.g. Daily's own
//                                                     'nonfatal-error') -- surfaced, not recovered
//                                                     from
//     onDisconnected(error)                        -- TT-3.1d/SCRUM-277: the LOCAL user's own
//                                                     connection to the provider ended
//                                                     unexpectedly (not because leave()/end()
//                                                     was called) -- see handleDisconnected()
//                                                     below for the reconnect flow this triggers.
//                                                     Never a remote participant leaving (that's
//                                                     onParticipantLeft), and NEVER anything that
//                                                     touches Session.status -- this composable
//                                                     has no code path that ever could.
export default function useVideoSession(session) {
    // TT-3.2c/SCRUM-310: 'removed' is deliberately its own status, not folded into 'ended' -- the
    // architect's own explicit requirement is that being forcibly removed by a counsellor must
    // never look like a transient network disconnect (which reconnects) OR like the whole room
    // ending (which is symmetric/nobody's "fault"). Only ever reached via the removed participant's
    // own listenForStatusChanges() handler below, never set locally by removeParticipant() itself
    // (that call is the COUNSELLOR's own action against someone else, not self-directed).
    const status = ref('idle') // idle | connecting | connected | payment_required | consent_required | ended | removed | error
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
    const reconnecting = ref(false)

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
    // True while a mute/camera toggle is still waiting on the provider (Chime may be starting a
    // device behind a permission prompt) -- the controls are disabled meanwhile, so a second click
    // can't race the first into publishing while the UI says off. mediaError explains a failure.
    const mediaBusy = ref(false)
    const mediaError = ref('')

    let client = null
    let channelName = null
    // TT-3.2c/SCRUM-310: read once, not re-derived per event -- the viewer's own id never changes
    // mid-call, and this is only ever compared against a VideoParticipantRemovedEvent's own
    // removedUserId to tell "I was the one removed" apart from "someone else was removed" (which
    // needs no local handling here at all -- the provider's own SDK already fires its normal
    // participant-left callback for every other browser in the call).
    const ownUserId = usePage().props.auth.user?.id

    function defaultCapabilities() {
        return {
            receiveOnly: false,
            canSendAudio: true,
            canSendVideo: true,
            isAnonymous: false,
            canRaiseHand: false,
            isMinorMember: false,
            canModerate: false,
            speakingGrantIdleTimeout: 300,
        }
    }

    // A receive-only member who currently holds a speaking grant -- the only viewer the idle
    // sweep can ever affect (counsellors and the creator never hold grants).
    const holdsSpeakingGrant = computed(() => capabilities.value.receiveOnly && capabilities.value.canSendAudio)

    function upsertParticipant({ id, isLocal, name, userId }) {
        const existingIndex = participants.value.findIndex((p) => p.id === id)

        // Daily's 'participant-joined' can fire before user_name is populated on the participant
        // object, with the real name only arriving on a later 'participant-updated' -- patch it
        // in rather than no-op entirely, or the tile stays stuck on the "Participant" fallback
        // for the whole call (review finding, 2026-09-11). TT-3.2c/SCRUM-310: userId is patched in
        // the same way for the same reason -- Chime's own presence callback can report a null
        // externalUserId on an early update before patching in the real one on a later call.
        if (existingIndex !== -1) {
            const patch = {}
            if (name && !participants.value[existingIndex].name) patch.name = name
            if (userId != null && participants.value[existingIndex].userId == null) patch.userId = userId

            if (Object.keys(patch).length) {
                participants.value.splice(existingIndex, 1, { ...participants.value[existingIndex], ...patch })
            }
            return
        }

        participants.value = [...participants.value, { id, isLocal, name, userId: userId ?? null }]
    }

    // Local-only: drops a tile once the provider's own SDK reports that participant gone (a normal
    // leave, or the provider-side effect of removeParticipant()'s own eject/DeleteAttendee call
    // reaching this browser). Deliberately named apart from the exported removeParticipant()
    // action below, which is the COUNSELLOR's own request to eject someone else server-side --
    // this one only ever reacts to a tile already being gone.
    function dropParticipantTile(id) {
        const leaving = participants.value.find((p) => p.id === id)
        participants.value = participants.value.filter((p) => p.id !== id)

        // TT-3.2f-j/SCRUM-327: someone who left can't be granted or revoked -- drop them from the
        // counsellor's queue too, unless another tile (a reconnect) still carries the same user.
        const userId = leaving?.userId
        if (userId == null || participants.value.some((p) => p.userId === userId)) return
        raisedHandUserIds.value = raisedHandUserIds.value.filter((uid) => uid !== userId)
        speakerUserIds.value = speakerUserIds.value.filter((uid) => uid !== userId)
    }

    function buildCallbacks() {
        return {
            onParticipantJoined: upsertParticipant,
            onParticipantLeft: dropParticipantTile,
            // Purely a signal -- VideoCallPanel.vue owns the actual <video> element refs and
            // decides when to (re-)call attachVideo(); there's no state to track here.
            onVideoAvailable: () => {},
            onConnectionQualityChanged: (quality) => {
                connectionQuality.value = quality
            },
            onError: (error) => {
                lastError.value = error?.message || error?.errorMsg || 'An unexpected video error occurred.'
            },
            onDisconnected: handleDisconnected,
        }
    }

    async function loadClient() {
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

    async function join() {
        if (!session.value?.id) return
        if (status.value === 'connecting' || status.value === 'connected') return

        const token = ++joinToken
        status.value = 'connecting'
        lastError.value = ''

        let credentials
        try {
            const response = await axios.post(route('sessions.video.join', session.value.id))
            credentials = response.data
        } catch (err) {
            if (token !== joinToken) return

            if (err.response?.status === 402) {
                status.value = 'payment_required'
                lastError.value = err.response?.data?.message || 'Payment is required to access video for this session.'
                return
            }

            // TT-3.1e-f/SCRUM-285: a dedicated flag from VideoConsentRequiredException, not a
            // string match on the message -- lets VideoCallPanel.vue show a specific "guardian
            // consent needed" banner instead of the generic error+retry UI, which would be
            // misleading here since retrying does nothing until a guardian actually acts.
            if (err.response?.data?.videoConsentRequired) {
                status.value = 'consent_required'
                lastError.value = err.response?.data?.message || 'Guardian video consent is required before this account can join video for this session.'
                return
            }

            lastError.value = err.response?.data?.message || 'Unable to join video. Please try again shortly.'
            status.value = 'error'
            return
        }

        if (token !== joinToken) {
            // Cancelled while the join HTTP call was in flight -- a VideoSession/room now
            // exists server-side with nobody about to join it locally, so notify the backend of
            // the abandoned join the same way a normal leave() would.
            axios.post(route('sessions.video.leave', session.value.id)).catch(() => {})
            return
        }

        // TT-3.2f-j/SCRUM-327 review finding: subscribed as soon as the join response arrives, not
        // after client.join()'s media setup (seconds, longer behind a camera prompt) -- a hand
        // raised or grant made in that window would otherwise never reach a counsellor's lists.
        applyJoinState(credentials.participant)
        listenForStatusChanges()

        try {
            client = await loadClient()

            if (token !== joinToken) {
                await client.destroy()
                client = null
                return
            }

            await client.join(credentials)

            if (token !== joinToken) {
                await client.destroy()
                client = null
                return
            }

            status.value = 'connected'
        } catch (err) {
            if (token !== joinToken) return

            stopIdleTracking()
            stopListeningForStatusChanges()

            // The join-video HTTP call already succeeded (a VideoSession/room exists) -- this is
            // strictly the SDK/media layer failing (camera/mic permission denial, a connection
            // failure to the provider's own servers), so it must never be confused with the
            // payment-gate/authorization failures caught above.
            lastError.value = 'Could not connect to the video call. Check your camera/microphone permissions and try again.'
            status.value = 'error'
            client = null
        }
    }

    // Called instead of leave() when a caller (VideoCallPanel.vue) goes away while status is
    // still 'connecting' -- leave() assumes a connected client/room to notify the backend about
    // in the normal way; this instead invalidates whichever join() is in flight so IT handles
    // its own teardown once its next await resolves (see the token checks above).
    async function cancel() {
        joinToken++
        // Not inside teardownClient()'s `if (client)` only -- join() now subscribes before a client
        // exists, so a cancel during loadClient() must unsubscribe too.
        stopIdleTracking()
        stopListeningForStatusChanges()
        if (client) await teardownClient()
    }

    // TT-3.1d/SCRUM-277: fired when the active provider client's own SDK reports the LOCAL
    // connection dropped out from under us (not a leave()/end() we called ourselves -- both
    // clients gate this via their own intentionalTeardown flag). Deliberately never touches
    // Session.status or calls sessions.video.leave/end -- a transient disconnect is not a leave,
    // the participant is still "in" the session's video from the backend's point of view (the
    // VideoSession epoch stays open; nothing here ends it), they just need fresh provider
    // credentials to reconnect to it. Rejoining reuses join() itself (the exact same code path a
    // first-time join takes -- same payment-gate/availability checks, same VideoSession epoch
    // reuse via JoinVideoSessionAction's own find-or-create logic) rather than any provider-
    // specific "resume" API, so this works identically for both providers without needing to
    // know which one is active.
    async function handleDisconnected() {
        if (status.value !== 'connected') return

        // Security-review finding (2026-09-11): captured here, the same way join() captures its
        // own token, so cancel() firing anywhere in this function's own async gaps (most
        // importantly while `staleClient.destroy()` is pending, the one window where `client` is
        // already null and cancel()'s `if (client) …` branch has nothing left to tear down) is
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

        reconnecting.value = true
        // Review finding (2026-09-11): deliberately does NOT stopListeningForStatusChanges() here
        // (unlike leave()/end()/teardownClient()) -- the whole point of staying subscribed
        // through the reconnect gap is so an "ended" broadcast arriving DURING it (e.g. the other
        // participant ending the call at the same moment this side disconnected) is still seen.
        // listenForStatusChanges()'s own handler below reacts to that by invalidating this same
        // token, which the check after the destroy() await picks up.

        const staleClient = client
        client = null
        participants.value = []
        // TT-3.2f-j/SCRUM-327 review finding: no activity pings for a call this browser isn't in --
        // and if the rejoin fails, nothing else would ever stop the timer. The rejoin's
        // applyJoinState() resets mute/camera/grant state from its own response.
        stopIdleTracking()
        // 'idle', not 'connecting' -- join()'s own re-entry guard only blocks 'connecting'/
        // 'connected', so this must clear 'connected' without tripping that guard when join() is
        // called again just below.
        status.value = 'idle'

        if (staleClient) {
            try {
                await staleClient.destroy()
            } catch (err) {
                // Best-effort, same reasoning as leave()/end()'s own provider-call handling --
                // the client is already gone from this composable's point of view either way.
            }
        }

        if (token !== joinToken) {
            // Cancelled while tearing down the stale client -- do not proceed to reconnect for a
            // caller that's already gone.
            reconnecting.value = false
            return
        }

        await join()
        reconnecting.value = false
    }

    async function leave() {
        if (!session.value?.id) return

        // Review finding (2026-09-11): invalidates any in-flight join()/handleDisconnected()
        // reconnect attempt -- without this, a disconnect racing this explicit "leave call"
        // click could complete its own automatic rejoin afterward and silently resurrect a call
        // the user just left.
        joinToken++

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

        await axios.post(route('sessions.video.participants.remove', {
            sessionId: session.value.id,
            userId: targetUserId,
        }))
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
        isMuted.value = !capabilities.value.canSendAudio
        isCameraOn.value = capabilities.value.canSendVideo
        handRaised.value = false
        speakingNotice.value = ''
        mediaError.value = ''

        const snapshotHands = capabilities.value.canModerate ? (state?.raisedHandUserIds ?? []) : []
        const snapshotSpeakers = capabilities.value.canModerate ? (state?.speakerUserIds ?? []) : []
        const merge = reconnecting.value && capabilities.value.canModerate

        raisedHandUserIds.value = merge ? [...new Set([...raisedHandUserIds.value, ...snapshotHands])] : snapshotHands
        speakerUserIds.value = merge ? [...new Set([...speakerUserIds.value, ...snapshotSpeakers])] : snapshotSpeakers

        stopIdleTracking()
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
            userId: targetUserId,
        }))
    }

    async function revokeSpeaking(targetUserId) {
        if (!session.value?.id) return
        await axios.post(route('sessions.video.participants.revoke_speaking', {
            sessionId: session.value.id,
            userId: targetUserId,
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
        const isSending = !isMuted.value || isCameraOn.value

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
        if (!capabilities.value.receiveOnly) return

        const wasGranted = holdsSpeakingGrant.value
        capabilities.value = { ...capabilities.value, canSendAudio, canSendVideo }
        handRaised.value = false

        if (canSendAudio) {
            // Granted: nothing is sent until the member chooses to unmute.
            isMuted.value = true
            isCameraOn.value = false
            speakingNotice.value = ''
            startIdleTracking()
            return
        }

        // A provider-level "make sure they're muted" (RevokeSpeakingPermissionAction::muteAtProvider)
        // also broadcasts for someone who was only ever watching -- nothing to undo or explain then.
        if (!wasGranted) return

        Promise.resolve(client?.disableLocalMedia()).catch(() => {})
        isMuted.value = true
        isCameraOn.value = false
        stopIdleTracking()
        speakingNotice.value = reason === 'IDLE_AUTO_EXPIRY'
            ? 'Your permission to speak ended because you stayed muted for a while. Raise your hand to ask again.'
            : 'A counsellor ended your permission to speak.'
    }

    async function teardownClient() {
        stopIdleTracking()
        stopListeningForStatusChanges()
        if (client) await client.destroy()
        client = null
        participants.value = []
    }

    async function toggleMute() {
        await toggleMedia(async () => {
            isMuted.value = await client.toggleMute()
        }, 'Could not use your microphone. Check your browser\'s microphone permission and try again.')
    }

    async function toggleCamera() {
        await toggleMedia(async () => {
            isCameraOn.value = await client.toggleCamera()
        }, 'Could not use your camera. Check your browser\'s camera permission and try again.')
    }

    async function toggleMedia(toggle, failureMessage) {
        if (!client || mediaBusy.value) return

        mediaBusy.value = true
        mediaError.value = ''

        try {
            await toggle()
            if (holdsSpeakingGrant.value) idleTick()

            // A revoke landed while this toggle was in flight (its broadcast already ran
            // applyOwnSpeakingPermission()) -- never leave a device on that the hidden controls
            // can no longer turn off.
            if (capabilities.value.receiveOnly && !holdsSpeakingGrant.value) {
                await Promise.resolve(client?.disableLocalMedia()).catch(() => {})
                isMuted.value = true
                isCameraOn.value = false
            }
        } catch (err) {
            mediaError.value = failureMessage
        } finally {
            mediaBusy.value = false
        }
    }

    function attachVideo(participantId, element) {
        client?.attachVideo(participantId, element)
    }

    function listenForStatusChanges() {
        // A reconnect deliberately keeps the listeners from the first join (see
        // handleDisconnected()) -- subscribing again would stack a second copy of every handler.
        if (channelName) return

        channelName = `sessions.${session.value.id}`
        window.Echo?.private(channelName).listen('.video-session.status-changed', (data) => {
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
            teardownClient()
            status.value = 'ended'
            reconnecting.value = false
        })

        // TT-3.2c/SCRUM-310: fires for EVERY participant when anyone is removed, but only the
        // removed user's own browser (data.removedUserId === ownUserId) needs to react -- every
        // other participant's tile list already updates on its own via the provider's normal
        // participant-left callback once the eject/DeleteAttendee call takes effect. Deliberately
        // its own `status.value = 'removed'`, never 'ended' -- see this composable's own top-of-
        // file status comment for why being removed must never look like the room simply ending.
        window.Echo?.private(channelName).listen('.video-session.participant-removed', (data) => {
            if (data.removedUserId !== ownUserId || status.value === 'ended' || status.value === 'removed') return

            joinToken++
            teardownClient()
            status.value = 'removed'
            reconnecting.value = false
        })

        // TT-3.2f-j/SCRUM-327: hand-raise and speaking broadcasts carry ids only (anonymity-safe);
        // counsellors resolve names from the call's own tiles. Each viewer also watches for its
        // OWN id, to follow its hand and speaking state.
        window.Echo?.private(channelName).listen('.video-session.hand-raised', (data) => {
            if (data.userId === ownUserId) handRaised.value = true
            if (capabilities.value.canModerate && !raisedHandUserIds.value.includes(data.userId)) {
                raisedHandUserIds.value = [...raisedHandUserIds.value, data.userId]
            }
        })

        window.Echo?.private(channelName).listen('.video-session.hand-lowered', (data) => {
            if (data.userId === ownUserId) handRaised.value = false
            if (capabilities.value.canModerate) {
                raisedHandUserIds.value = raisedHandUserIds.value.filter((uid) => uid !== data.userId)
            }
        })

        window.Echo?.private(channelName).listen('.video-session.speaking-permission-changed', (data) => {
            if (data.userId === ownUserId) applyOwnSpeakingPermission(data)

            if (!capabilities.value.canModerate) return
            speakerUserIds.value = speakerUserIds.value.filter((uid) => uid !== data.userId)
            if (data.canSendAudio) speakerUserIds.value = [...speakerUserIds.value, data.userId]
        })
    }

    function stopListeningForStatusChanges() {
        if (!channelName) return

        // Deliberately .stopListening(), NOT Echo.leave() -- TherapyComponent.vue already owns
        // this same sessions.{id} channel's lifecycle for message events and calls Echo.leave()
        // itself in its own onBeforeUnmount; an independent Echo.leave() here would risk tearing
        // the channel down out from under that listener if unmount ordering differs.
        window.Echo?.private(channelName).stopListening('.video-session.status-changed')
        window.Echo?.private(channelName).stopListening('.video-session.participant-removed')
        window.Echo?.private(channelName).stopListening('.video-session.hand-raised')
        window.Echo?.private(channelName).stopListening('.video-session.hand-lowered')
        window.Echo?.private(channelName).stopListening('.video-session.speaking-permission-changed')
        channelName = null
    }

    return {
        status, participants, isMuted, isCameraOn, connectionQuality, lastError, reconnecting,
        capabilities, holdsSpeakingGrant, handRaised, raisedHandUserIds, speakerUserIds, speakingNotice,
        idleSecondsRemaining, mediaBusy, mediaError,
        join, leave, end, removeParticipant, cancel, toggleMute, toggleCamera, attachVideo,
        raiseHand, lowerHand, grantSpeaking, revokeSpeaking,
    }
}
