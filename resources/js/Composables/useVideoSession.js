import { ref } from 'vue'
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
//     toggleMute(): boolean                  -- returns the new isMuted state
//     toggleCamera(): boolean                -- returns the new isCameraOn state
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
//     onParticipantJoined({ id, isLocal, name })  -- name may be null (Chime has no display-name
//                                                     field at all -- see ChimeVideoClient.js)
//     onParticipantLeft(id)
//     onVideoAvailable(id)                        -- this participant's video is ready;
//                                                     VideoCallPanel.vue should call attachVideo
//     onConnectionQualityChanged(quality)          -- 'good' | 'poor' | 'unknown'
//     onError(error)
export default function useVideoSession(session) {
    const status = ref('idle') // idle | connecting | connected | payment_required | ended | error
    const participants = ref([])
    const isMuted = ref(false)
    const isCameraOn = ref(true)
    const connectionQuality = ref('unknown')
    const lastError = ref('')

    let client = null
    let channelName = null

    function upsertParticipant({ id, isLocal, name }) {
        const existingIndex = participants.value.findIndex((p) => p.id === id)

        // Daily's 'participant-joined' can fire before user_name is populated on the participant
        // object, with the real name only arriving on a later 'participant-updated' -- patch it
        // in rather than no-op entirely, or the tile stays stuck on the "Participant" fallback
        // for the whole call (review finding, 2026-09-11).
        if (existingIndex !== -1) {
            if (name && !participants.value[existingIndex].name) {
                participants.value.splice(existingIndex, 1, { ...participants.value[existingIndex], name })
            }
            return
        }

        participants.value = [...participants.value, { id, isLocal, name }]
    }

    function removeParticipant(id) {
        participants.value = participants.value.filter((p) => p.id !== id)
    }

    function buildCallbacks() {
        return {
            onParticipantJoined: upsertParticipant,
            onParticipantLeft: removeParticipant,
            // Purely a signal -- VideoCallPanel.vue owns the actual <video> element refs and
            // decides when to (re-)call attachVideo(); there's no state to track here.
            onVideoAvailable: () => {},
            onConnectionQualityChanged: (quality) => {
                connectionQuality.value = quality
            },
            onError: (error) => {
                lastError.value = error?.message || error?.errorMsg || 'An unexpected video error occurred.'
            },
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

            listenForStatusChanges()
            status.value = 'connected'
        } catch (err) {
            if (token !== joinToken) return

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
        if (client) await teardownClient()
    }

    async function leave() {
        if (!session.value?.id) return

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

        try {
            await axios.post(route('sessions.video.end', session.value.id))
        } catch (err) {
            // Best-effort, same reasoning as leave() above.
        }

        await teardownClient()
        status.value = 'ended'
    }

    async function teardownClient() {
        stopListeningForStatusChanges()
        if (client) await client.destroy()
        client = null
        participants.value = []
    }

    function toggleMute() {
        if (!client) return
        isMuted.value = client.toggleMute()
    }

    function toggleCamera() {
        if (!client) return
        isCameraOn.value = client.toggleCamera()
    }

    function attachVideo(participantId, element) {
        client?.attachVideo(participantId, element)
    }

    function listenForStatusChanges() {
        channelName = `sessions.${session.value.id}`
        window.Echo?.private(channelName).listen('.video-session.status-changed', (data) => {
            // The room ended for everyone (e.g. the other participant ended it) -- react locally
            // even though we didn't call end() ourselves. Deliberately does NOT re-issue the
            // sessions.video.end HTTP call -- that's the ender's own action, already done.
            if (data.status === 'ended' && status.value === 'connected') {
                teardownClient()
                status.value = 'ended'
            }
        })
    }

    function stopListeningForStatusChanges() {
        if (!channelName) return

        // Deliberately .stopListening(), NOT Echo.leave() -- TherapyComponent.vue already owns
        // this same sessions.{id} channel's lifecycle for message events and calls Echo.leave()
        // itself in its own onBeforeUnmount; an independent Echo.leave() here would risk tearing
        // the channel down out from under that listener if unmount ordering differs.
        window.Echo?.private(channelName).stopListening('.video-session.status-changed')
        channelName = null
    }

    return {
        status, participants, isMuted, isCameraOn, connectionQuality, lastError,
        join, leave, end, toggleMute, toggleCamera, attachVideo,
    }
}
