import DailyIframe from '@daily-co/daily-js'
import { classifyDailyStop, dailyQuality } from '@/Video/connectionPolicy'
import { dailyRemoteVideoOn, networkPauseMessage, parseNetworkPauseMessage } from '@/Video/audioOnlyPolicy'

// TT-3.1c/SCRUM-276: thin wrapper around @daily-co/daily-js's "call object" (headless, no
// prebuilt iframe UI) mode -- gives useVideoSession.js a provider-agnostic surface to drive a
// custom video UI from. See ChimeVideoClient.js for the Amazon Chime SDK counterpart; both
// implement the exact factory shape documented at the top of useVideoSession.js.
//
// Verified against Daily's own docs (2026-09): createCallObject() runs headless with raw
// MediaStreamTrack access via participant.tracks.{audio|video}.persistentTrack (NOT the
// deprecated track/videoTrack/audioTrack fields -- those don't survive every non-playable
// state), and destroy() alone is sufficient for full teardown (it leaves the call automatically
// first if still active). There is no separate "end for everyone" SDK call -- ending the room
// for everyone is our own backend's DeleteRoom API call (DailyClient::deleteRoom), never
// anything the joining browser does -- so leave() and end() are deliberately identical here.
// TT-3.3c/SCRUM-460: "audio only" subscriptions -- everyone's audio (screen-share audio too), no video.
function audioOnlyTracks() {
    return { audio: true, screenAudio: true, video: false, screenVideo: false }
}

export default function createDailyVideoClient(callbacks) {
    let callObject = null
    let audioEnabled = true
    let videoEnabled = true
    // TT-3.1d/SCRUM-277: set right before WE call destroy() (leave()/end()) so the 'error' handler
    // below can tell "the call ended because we tore it down on purpose" apart from "the call
    // ended out from under us" -- only the latter is a disconnect useVideoSession.js should try to
    // recover from.
    let intentionalTeardown = false
    // TT-3.3b/SCRUM-459: which of Daily's own connections ('signaling', 'sfu', 'peer-to-peer') are
    // currently interrupted -- Daily is reconnecting while any is (see setInterrupted()).
    const interruptedConnections = new Set()
    const attachedElements = new Map()
    // TT-3.3c/SCRUM-460: the last camera state reported per remote id, so only a change is reported.
    const remoteVideoOn = new Map()
    // TT-3.3c/SCRUM-460: false in "audio only" -- subscribed to everyone's audio, nobody's video.
    let receivingVideo = true

    function idFor(participant) {
        return participant.local ? 'local' : participant.session_id
    }

    function handleParticipant(participant) {
        const id = idFor(participant)

        callbacks.onParticipantJoined?.({
            id,
            isLocal: !!participant.local,
            // SCRUM-334: deliberately NOT participant.user_name -- Daily lets every participant
            // change their own (setUserName()) and no token/room property prevents it, so a masked
            // participant could relabel themselves as a counsellor. useVideoSession.js resolves
            // every label from our server by userId instead.
            // TT-3.2c/SCRUM-310, SCRUM-357: the participant key DailyVideoProvider sent as `user_id`
            // when minting this participant's token (never a user id; settable only via the token,
            // so the participant can't change it -- SCRUM-334) -- distinct from `id` above
            // (Daily's own tile/session_id), so a counsellor's "remove" click names the right
            // participant rather than a Daily-internal id the backend has never seen.
            userId: participant.user_id ?? null,
        })

        if (!participant.local) reportRemoteVideo(id, participant)
        attachIfReady(id, participant)
    }

    // TT-3.3c/SCRUM-460: a remote camera turning off/on, from Daily's own track state (never from
    // anything the participant says about themselves) -- drives the tile's camera-off placeholder.
    // Not while unsubscribed from video ("audio only"): the track state then says what WE asked for,
    // not whether their camera is on.
    function reportRemoteVideo(id, participant) {
        if (!receivingVideo) return
        const isOn = dailyRemoteVideoOn(participant.tracks?.video?.state)
        if (isOn === null || remoteVideoOn.get(id) === isOn) return
        remoteVideoOn.set(id, isOn)
        callbacks.onRemoteVideoChanged?.(id, isOn)
    }

    function attachIfReady(id, participant) {
        const videoTrack = participant.tracks?.video?.persistentTrack
        const audioTrack = participant.tracks?.audio?.persistentTrack

        if (!videoTrack && !audioTrack) return

        callbacks.onVideoAvailable?.(id)

        const element = attachedElements.get(id)
        if (element) element.srcObject = new MediaStream([videoTrack, audioTrack].filter(Boolean))
    }

    // Applies a change to interruptedConnections, reporting only an actual flip of "reconnecting".
    function setInterrupted(change) {
        if (intentionalTeardown) return
        const was = interruptedConnections.size > 0
        change()
        const now = interruptedConnections.size > 0
        if (now !== was) callbacks.onReconnecting?.(now)
    }

    async function join(credentials, { startVideoOff = false, startAudioOff = false } = {}) {
        callObject = DailyIframe.createCallObject()

        callObject.on('participant-joined', (e) => {
            // TT-3.3c/SCRUM-460: with automatic subscription off ("audio only"), a newcomer is
            // subscribed to nothing until told -- their audio, never their video.
            if (!receivingVideo && e?.participant && !e.participant.local) {
                try {
                    callObject.updateParticipant(e.participant.session_id, { setSubscribedTracks: audioOnlyTracks() })
                } catch (err) {
                    // Left again already -- nothing to subscribe to.
                }
            }
            handleParticipant(e.participant)
        })
        callObject.on('participant-updated', (e) => handleParticipant(e.participant))
        callObject.on('participant-left', (e) => {
            const id = idFor(e.participant)
            callbacks.onParticipantLeft?.(id)
            attachedElements.delete(id)
            remoteVideoOn.delete(id)
        })
        // TT-3.3c/SCRUM-460: another person's "my video is paused for my connection" flag. Attributed
        // by Daily's own fromId (their session_id, the same id as their tile), never by anything in
        // the payload, which must be exactly { ttNetworkPause: boolean } -- anything else is ignored.
        callObject.on('app-message', (e) => {
            const paused = parseNetworkPauseMessage(e?.data)
            if (paused === null || typeof e?.fromId !== 'string') return
            const local = callObject?.participants()?.local
            if (local && e.fromId === local.session_id) return
            callbacks.onRemoteNetworkPause?.(e.fromId, paused)
        })
        // TT-3.3b/SCRUM-459: Daily's 'warning' is our 'poor' -- see connectionPolicy.js.
        callObject.on('network-quality-change', (e) => {
            const quality = dailyQuality(e.networkState)
            // A good reading means media is flowing again -- never leave "Reconnecting…" stuck on a
            // 'connected' event Daily didn't send (or sent for a different connection type).
            if (quality === 'good') setInterrupted(() => interruptedConnections.clear())
            callbacks.onConnectionQualityChanged?.(quality)
        })
        // TT-3.3b/SCRUM-459: Daily reconnecting by itself (event 'interrupted', then 'connected').
        // Any 'connected' clears the lot: the call is back, whichever connection said so.
        callObject.on('network-connection', (e) => {
            if (e?.event === 'interrupted' && e.type) setInterrupted(() => interruptedConnections.add(e.type))
            else if (e?.event === 'connected') setInterrupted(() => interruptedConnections.clear())
        })
        // TT-3.1d/SCRUM-277: Daily's own docs describe 'error' (unlike 'nonfatal-error') as
        // meaning the call itself has ended -- an unrequested one is a disconnect, not just a
        // reportable problem, so it routes to onDisconnected rather than onError.
        // TT-3.3b/SCRUM-459: classified first -- 'error' also fires for being ejected, the room
        // expiring or not existing, a full meeting, etc., none of which a rejoin may undo. Only
        // connectionPolicy.js's allow-list (connection-error, exp-token) is ever rejoined.
        callObject.on('error', (e) => {
            if (intentionalTeardown) return
            callbacks.onDisconnected?.(classifyDailyStop(e?.error?.type))
        })
        callObject.on('nonfatal-error', (e) => callbacks.onError?.(e))

        // TT-3.2f-j/SCRUM-327: a receive-only member (or the audio-only creator of an anonymous
        // group, for video) joins with those devices off, so the browser never asks for a camera or
        // mic the token can't send from anyway. Daily enforces the token's canSend regardless.
        const capabilities = credentials.participant ?? { canSendAudio: true, canSendVideo: true }
        // TT-3.3c/SCRUM-460: startVideoOff/startAudioOff -- an automatic rejoin keeps the person's own
        // camera-off and mute, and the app's pause/"audio only", rather than switching them on.
        audioEnabled = capabilities.canSendAudio && !startAudioOff
        videoEnabled = capabilities.canSendVideo && !startVideoOff

        await callObject.join({
            url: credentials.url,
            token: credentials.token,
            startAudioOff: !audioEnabled,
            startVideoOff: !videoEnabled,
        })

        // Belt-and-braces: 'participant-joined' is expected to fire for the local participant
        // too, but explicitly processing callObject.participants().local here means the local
        // tile never silently fails to appear if that assumption is ever wrong.
        const local = callObject.participants()?.local
        if (local) handleParticipant(local)
    }

    function attachVideo(id, element) {
        attachedElements.set(id, element)

        const participants = callObject?.participants() || {}
        const participant = id === 'local'
            ? participants.local
            : Object.values(participants).find((p) => p.session_id === id)

        if (participant) attachIfReady(id, participant)
    }

    function toggleMute() {
        audioEnabled = !audioEnabled
        callObject?.setLocalAudio(audioEnabled)
        return !audioEnabled
    }

    function toggleCamera() {
        videoEnabled = !videoEnabled
        callObject?.setLocalVideo(videoEnabled)
        return videoEnabled
    }

    // TT-3.3c/SCRUM-460: the app pausing/restoring the camera for the connection -- never the mic.
    // Returns the new isCameraOn. Daily enforces the token's canSend regardless.
    function setCamera(on) {
        videoEnabled = !!on
        callObject?.setLocalVideo(videoEnabled)
        return videoEnabled
    }

    // TT-3.3c/SCRUM-460: "audio only"'s receive side -- actually stop receiving video. daily-js only
    // accepts setSubscribedTracks once automatic subscription is off (it validates this, and
    // setSubscribeToTracksAutomatically() updates the flag it checks synchronously), so: automatic
    // off, then everyone subscribed to audio only; newcomers meanwhile get the same in
    // 'participant-joined'. Leaving: everyone subscribed to everything again, then automatic back on.
    // (SCRUM-317: confirm on a real room, SFU and peer-to-peer, that video stops arriving and comes
    // back for everyone, newcomers included.)
    function setReceiveVideo(on) {
        receivingVideo = !!on
        if (!callObject) return
        if (receivingVideo) {
            callObject.updateParticipants({ '*': { setSubscribedTracks: true } })
            callObject.setSubscribeToTracksAutomatically(true)
            return
        }
        callObject.setSubscribeToTracksAutomatically(false)
        callObject.updateParticipants({ '*': { setSubscribedTracks: audioOnlyTracks() } })
    }

    // TT-3.3c/SCRUM-460: whether Daily itself still lets this person send video (their token's
    // permissions, updated live by our server's update-permissions call), or null if it can't say.
    function providerAllowsVideo() {
        const canSend = callObject?.participants()?.local?.permissions?.canSend
        if (typeof canSend === 'boolean') return canSend
        if (canSend instanceof Set) return canSend.has('video')
        return null
    }

    // TT-3.3c/SCRUM-460: tell everyone else our video is (no longer) paused for the connection.
    // Best effort: a lost message only means a plain "camera off" tile instead of the reason.
    function sendNetworkPause(paused) {
        try {
            callObject?.sendAppMessage(networkPauseMessage(paused), '*')
        } catch (err) {
            // Not joined (yet/any more) -- nothing to tell.
        }
    }

    // TT-3.2f-j/SCRUM-327: a revoked speaking permission -- stop sending locally too, rather than
    // leave the UI showing a live mic the provider is already dropping.
    function disableLocalMedia() {
        audioEnabled = false
        videoEnabled = false
        callObject?.setLocalAudio(false)
        callObject?.setLocalVideo(false)
    }

    function teardown() {
        intentionalTeardown = true
        callObject?.destroy()
    }

    // Deliberately identical -- see this file's own top comment.
    function leave() { return teardown() }
    function end() { return teardown() }

    function destroy() {
        teardown()
        callObject = null
        attachedElements.clear()
        remoteVideoOn.clear()
    }

    return {
        join, leave, end, toggleMute, toggleCamera, setCamera, setReceiveVideo, sendNetworkPause, providerAllowsVideo,
        disableLocalMedia, attachVideo, destroy,
    }
}
