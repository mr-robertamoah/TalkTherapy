import DailyIframe from '@daily-co/daily-js'

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
export default function createDailyVideoClient(callbacks) {
    let callObject = null
    let audioEnabled = true
    let videoEnabled = true
    // TT-3.1d/SCRUM-277: set right before WE call destroy() (leave()/end()) so the 'error' handler
    // below can tell "the call ended because we tore it down on purpose" apart from "the call
    // ended out from under us" -- only the latter is a disconnect useVideoSession.js should try to
    // recover from.
    let intentionalTeardown = false
    const attachedElements = new Map()

    function idFor(participant) {
        return participant.local ? 'local' : participant.session_id
    }

    function handleParticipant(participant) {
        const id = idFor(participant)

        callbacks.onParticipantJoined?.({
            id,
            isLocal: !!participant.local,
            // The on-screen label a real Daily UI would show -- exactly the anonymity-aware
            // display name JoinVideoSessionAction::displayNameFor() already resolved server-side
            // (see DailyVideoProvider::createParticipantCredentials()'s own `user_name` field).
            name: participant.user_name || null,
        })

        attachIfReady(id, participant)
    }

    function attachIfReady(id, participant) {
        const videoTrack = participant.tracks?.video?.persistentTrack
        const audioTrack = participant.tracks?.audio?.persistentTrack

        if (!videoTrack && !audioTrack) return

        callbacks.onVideoAvailable?.(id)

        const element = attachedElements.get(id)
        if (element) element.srcObject = new MediaStream([videoTrack, audioTrack].filter(Boolean))
    }

    async function join(credentials) {
        callObject = DailyIframe.createCallObject()

        callObject.on('participant-joined', (e) => handleParticipant(e.participant))
        callObject.on('participant-updated', (e) => handleParticipant(e.participant))
        callObject.on('participant-left', (e) => {
            const id = idFor(e.participant)
            callbacks.onParticipantLeft?.(id)
            attachedElements.delete(id)
        })
        callObject.on('network-quality-change', (e) => {
            callbacks.onConnectionQualityChanged?.(e.networkState ?? 'unknown')
        })
        // TT-3.1d/SCRUM-277: Daily's own docs describe 'error' (unlike 'nonfatal-error') as
        // meaning the call itself has ended -- an unrequested one is exactly a disconnect, not
        // just a reportable problem, so it routes to onDisconnected (reconnect-worthy) rather
        // than onError (which useVideoSession.js only ever surfaces as a dead-end message).
        callObject.on('error', (e) => {
            if (intentionalTeardown) return
            callbacks.onDisconnected?.(e)
        })
        callObject.on('nonfatal-error', (e) => callbacks.onError?.(e))

        await callObject.join({ url: credentials.url, token: credentials.token })

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
    }

    return { join, leave, end, toggleMute, toggleCamera, attachVideo, destroy }
}
