import { acquireChannel } from '@/Composables/sharedChannel'

// SCRUM-458 (TT-3.3a): the browser half of App\Services\Fake\FakeVideoProvider -- a call with no
// provider behind it, for local development and Playwright QA without Daily/Chime keys. Implements
// the same factory shape as DailyVideoClient.js / ChimeVideoClient.js (documented at the top of
// useVideoSession.js) and reports through the same callbacks with the same payloads, so
// useVideoSession.js treats it like either real client. The server only binds the fake in
// local/testing; this file is only ever loaded when the `videoProvider` shared prop says 'fake'.
//
// What it stands in for:
// - Sending capability: credentials.canSend ({ audio, video }) plays the part of the provider token
//   (Daily's permissions.canSend, Chime's attendee Capabilities). A device it doesn't allow is never
//   opened, at join or later -- toggling it on rejects, which the call UI shows as a media error.
//   NOT credentials.participant, which is display-only, exactly as for the real clients.
// - Live permission changes: a real provider hears our server's update-permissions call itself.
//   The fake's server half has nobody to tell, so this client listens for the same
//   speaking-permission-changed broadcast our server sends alongside that call, for its own key.
// - Media: the local camera/mic come from getUserMedia only when allowed and switched on
//   (Playwright's fake media devices work). With no camera at all, a drawn placeholder is used
//   instead. A refused permission still fails, as it does with Daily/Chime.
// - Other people: there is no room, so no one else ever appears by themselves -- the dev hook below
//   adds placeholder participants.
//
// Dev-only simulation hook (never in a production build: import.meta.env.DEV is false there and the
// block is dropped):
//   window.__ttFakeVideo = {
//     setQuality(q)            -- 'good' | 'poor' | 'bad' | 'warning' | 'unknown', passed straight to
//                                 onConnectionQualityChanged (Daily also passes its raw
//                                 networkState through, which can be 'warning'/'bad')
//     dropRecoverable()        -- the connection dropped out from under us (reconnect-worthy)
//     stopTerminal(kind)       -- 'ejected' | 'meeting-ended' | 'room-expired' | 'removed'
//     nonFatalError(message?)  -- a reportable problem that doesn't end the call (onError)
//     addParticipant(key?)     -- a placeholder remote participant; returns its tile id. Pass a real
//                                 participant key to have its name resolved by the server.
//     removeParticipant(id)    -- that placeholder leaves
//     state()                  -- { connected, canSend, audioOn, videoOn, remotes }
//   }
//
// Stop mapping (SCRUM-459 will distinguish recoverable from terminal stops in the real clients;
// until then every stop below is reported exactly as DailyVideoClient.js reports a Daily 'error'
// event -- onDisconnected with Daily's own fatal-error shape -- so useVideoSession.js reacts as it
// would with Daily today, i.e. it tries to reconnect). `fakeStopKind` names the simulated case so
// SCRUM-459 can branch on it without parsing Daily types:
//   dropRecoverable()             -> error.type 'connection-error'
//   stopTerminal('ejected')       -> error.type 'ejected'   (provider-side eject, e.g. token expiry)
//   stopTerminal('removed')       -> error.type 'ejected'   (our server's eject call; the app's own
//                                    participant-removed broadcast is what marks it 'removed')
//   stopTerminal('meeting-ended') -> error.type 'no-room'   (the room was deleted, sessions.video.end)
//   stopTerminal('room-expired')  -> error.type 'exp-room'  (Daily's eject_at_room_exp)
const STOP_TYPES = {
    'connection-error': 'connection-error',
    ejected: 'ejected',
    removed: 'ejected',
    'meeting-ended': 'no-room',
    'room-expired': 'exp-room',
}

// getUserMedia errors meaning "no usable device" (missing, busy elsewhere, or none matching) -- the
// fake carries on without one. NotAllowedError (a refused permission) is deliberately not here.
const NO_DEVICE_ERRORS = ['NotFoundError', 'NotReadableError', 'OverconstrainedError']

export default function createFakeVideoClient(callbacks) {
    let credentials = null
    let connected = false
    // Set before WE tear down, so a teardown never reads as a disconnect (same flag as Daily/Chime).
    let intentionalTeardown = false
    const canSend = { audio: false, video: false }
    let audioTrack = null
    let videoTrack = null
    let placeholderVideo = null
    let channel = null
    // Bumped by disableLocalMedia() -- a toggle still awaiting getUserMedia when a revoke lands must
    // not switch the device back on afterwards (same guard as ChimeVideoClient.js).
    let mediaGeneration = 0
    let remoteCount = 0
    const remotes = new Map() // tile id -> { key, stream, stop }
    const attachedElements = new Map()
    let devHook = null

    async function join(creds) {
        credentials = creds
        canSend.audio = !!creds.canSend?.audio
        canSend.video = !!creds.canSend?.video

        // A failure part-way (the mic opened, then the camera refused) releases whatever did open,
        // so a failed join never leaves a device on.
        try {
            if (canSend.audio) await startAudio()
            if (canSend.video) await startVideo()
        } catch (err) {
            release()
            throw err
        }

        listenForPermissionChanges()
        connected = true
        if (import.meta.env.DEV) exposeDevHook()

        callbacks.onParticipantJoined?.({ id: 'local', isLocal: true, userId: creds.participantKey ?? null })
        if (videoTrack) callbacks.onVideoAvailable?.('local')
        callbacks.onConnectionQualityChanged?.('good')
    }

    // A real provider learns of a grant/revoke from our server directly; see the top comment.
    function listenForPermissionChanges() {
        if (!window.Echo || !credentials?.sessionId) return

        channel = acquireChannel(`sessions.${credentials.sessionId}`)
        channel.listen('.video-session.speaking-permission-changed', (data) => {
            if (data.participantKey !== credentials.participantKey) return
            canSend.audio = !!data.canSendAudio
            canSend.video = !!data.canSendVideo
            // Standing in for provider enforcement: also cancels a device start still awaiting
            // getUserMedia, so it can't come back on after the revoke (see toggleMute/toggleCamera).
            if (!canSend.audio || !canSend.video) mediaGeneration++
            if (!canSend.audio) stopAudio()
            if (!canSend.video) stopVideo()
        })
    }

    async function startAudio() {
        if (!navigator.mediaDevices?.getUserMedia) return
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true })
            audioTrack = stream.getAudioTracks()[0] ?? null
        } catch (err) {
            // No usable microphone: carry on silent. A refused permission fails like a real client.
            if (!NO_DEVICE_ERRORS.includes(err?.name)) throw err
        }
    }

    async function startVideo() {
        try {
            if (!navigator.mediaDevices?.getUserMedia) throw Object.assign(new Error('No media devices'), { name: 'NotFoundError' })
            const stream = await navigator.mediaDevices.getUserMedia({ video: true })
            videoTrack = stream.getVideoTracks()[0] ?? null
        } catch (err) {
            // No usable camera: a drawn placeholder instead. A refused permission still fails.
            if (!NO_DEVICE_ERRORS.includes(err?.name)) throw err
            placeholderVideo = placeholderStream('You')
            videoTrack = placeholderVideo.stream.getVideoTracks()[0] ?? null
        }
        bindLocal()
    }

    function stopAudio() {
        audioTrack?.stop()
        audioTrack = null
    }

    function stopVideo() {
        videoTrack?.stop()
        videoTrack = null
        placeholderVideo?.stop()
        placeholderVideo = null
        bindLocal()
    }

    function bindLocal() {
        const element = attachedElements.get('local')
        if (element) element.srcObject = videoTrack ? new MediaStream([videoTrack]) : null
    }

    // A canvas drawing a label and a moving bar, so a tile visibly plays without any camera.
    function placeholderStream(label) {
        const canvas = document.createElement('canvas')
        canvas.width = 320
        canvas.height = 180
        const context = canvas.getContext('2d')
        let frame = 0
        const timer = setInterval(() => {
            frame = (frame + 4) % canvas.width
            context.fillStyle = '#1f2937'
            context.fillRect(0, 0, canvas.width, canvas.height)
            context.fillStyle = '#9ca3af'
            context.fillRect(frame, canvas.height - 8, 40, 4)
            context.fillStyle = '#f9fafb'
            context.font = '16px sans-serif'
            context.fillText(label, 16, 32)
        }, 100)
        const stream = canvas.captureStream ? canvas.captureStream(10) : new MediaStream()

        return {
            stream,
            stop() {
                clearInterval(timer)
                stream.getTracks().forEach((track) => track.stop())
            },
        }
    }

    function attachVideo(id, element) {
        attachedElements.set(id, element)
        if (id === 'local') return bindLocal()

        const remote = remotes.get(id)
        if (remote) element.srcObject = remote.stream
    }

    function refused(kind) {
        return new Error(`The video service does not allow this participant to send ${kind}.`)
    }

    // Like Daily: returns the new isMuted. Starts the mic lazily, as a receive-only join opens none.
    // With no usable microphone (startAudio() carried on silent) there is no track to enable, so
    // unmuting reports still muted rather than pretending -- Playwright's fake devices avoid this.
    async function toggleMute() {
        if (audioTrack?.enabled) {
            audioTrack.enabled = false
            return true
        }
        if (!canSend.audio) throw refused('audio')

        const generation = mediaGeneration
        if (!audioTrack) await startAudio()
        if (generation !== mediaGeneration || !connected) {
            stopAudio()
            return true
        }
        if (audioTrack) audioTrack.enabled = true
        return !audioTrack
    }

    // Returns the new isCameraOn.
    async function toggleCamera() {
        if (videoTrack) {
            stopVideo()
            return false
        }
        if (!canSend.video) throw refused('video')

        const generation = mediaGeneration
        await startVideo()
        if (generation !== mediaGeneration || !connected) {
            stopVideo()
            return false
        }
        if (videoTrack) callbacks.onVideoAvailable?.('local')
        return !!videoTrack
    }

    function disableLocalMedia() {
        mediaGeneration++
        stopAudio()
        stopVideo()
    }

    function addRemote(key = null) {
        const id = `fake-remote-${++remoteCount}`
        const media = placeholderStream(`Participant ${remoteCount}`)
        remotes.set(id, { key, ...media })
        callbacks.onParticipantJoined?.({ id, isLocal: false, userId: key })
        callbacks.onVideoAvailable?.(id)
        const element = attachedElements.get(id)
        if (element) element.srcObject = media.stream
        return id
    }

    function removeRemote(id) {
        const remote = remotes.get(id)
        if (!remote) return
        remote.stop()
        remotes.delete(id)
        attachedElements.delete(id)
        callbacks.onParticipantLeft?.(id)
    }

    // The call ends out from under us: local media goes, then the same report Daily's 'error' gives.
    function simulateStop(kind) {
        if (!connected || intentionalTeardown) return
        const type = STOP_TYPES[kind]
        if (!type) throw new Error(`Unknown stop kind: ${kind}`)

        release()
        callbacks.onDisconnected?.({
            action: 'error',
            errorMsg: `Fake video call stopped (${kind}).`,
            error: { type, msg: `Fake video call stopped (${kind}).` },
            fakeStopKind: kind,
        })
    }

    function exposeDevHook() {
        devHook = {
            setQuality: (quality) => callbacks.onConnectionQualityChanged?.(quality),
            dropRecoverable: () => simulateStop('connection-error'),
            stopTerminal: (kind) => simulateStop(kind),
            nonFatalError: (message = 'Fake non-fatal video error.') => callbacks.onError?.({ action: 'nonfatal-error', type: 'fake', errorMsg: message }),
            addParticipant: (key) => addRemote(key),
            removeParticipant: (id) => removeRemote(id),
            state: () => ({
                connected,
                canSend: { ...canSend },
                audioOn: !!audioTrack?.enabled,
                videoOn: !!videoTrack,
                remotes: [...remotes.entries()].map(([id, remote]) => ({ id, key: remote.key })),
            }),
        }
        window.__ttFakeVideo = devHook
    }

    function release() {
        connected = false
        mediaGeneration++
        stopAudio()
        stopVideo()
        for (const remote of remotes.values()) remote.stop()
        remotes.clear()
        channel?.release()
        channel = null
    }

    function teardown() {
        intentionalTeardown = true
        release()
    }

    // Deliberately identical, as for Daily/Chime: ending for everyone is the server's job.
    function leave() { return teardown() }
    function end() { return teardown() }

    function destroy() {
        teardown()
        attachedElements.clear()
        // Only our own hook: a reconnect's new client may already have put up its own.
        if (import.meta.env.DEV && devHook && window.__ttFakeVideo === devHook) delete window.__ttFakeVideo
        devHook = null
    }

    return { join, leave, end, toggleMute, toggleCamera, disableLocalMedia, attachVideo, destroy }
}
