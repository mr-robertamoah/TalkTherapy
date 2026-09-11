import {
    ConsoleLogger,
    DefaultDeviceController,
    DefaultMeetingSession,
    LogLevel,
    MeetingSessionConfiguration,
} from 'amazon-chime-sdk-js'

// TT-3.1c/SCRUM-276: thin wrapper around amazon-chime-sdk-js's meeting-session API -- see
// DailyVideoClient.js for the Daily.co counterpart and the factory shape both must implement
// (documented at the top of useVideoSession.js).
//
// Known limitation (2026-09, accepted for this ticket): unlike Daily's `user_name` field, Chime's
// CreateAttendee API has no display-name concept at all -- ChimeVideoProvider::createParticipantCredentials()
// (TT-3.1a) only ever sends ExternalUserId (the raw numeric User id). A Chime-deployment
// participant tile therefore has no human-readable name to show, only its raw attendee id --
// VideoCallPanel.vue falls back to a generic "Participant" label for it. Daily is this app's
// default VIDEO_PROVIDER; revisit if a Chime-configured deployment needs proper names later.
export default function createChimeVideoClient(callbacks) {
    let meetingSession = null
    let videoEnabled = false
    let localAttendeeId = null
    let presenceCallback = null
    // TT-3.1d/SCRUM-277: set right before WE call stop() (via teardown(), from leave()/end()) so
    // audioVideoDidStop below can tell "we stopped it on purpose" apart from "it stopped out from
    // under us" -- only the latter is a disconnect useVideoSession.js should try to recover from.
    // Replaces an earlier `if (!meetingSession) return` guard that was unreliable: destroy()
    // didn't null meetingSession until AFTER teardown()/stop() resolved, so an intentional stop
    // could still see meetingSession non-null at the exact moment this callback fired.
    let intentionalTeardown = false
    const attachedElements = new Map()
    const tileIdToParticipantId = new Map()

    const observer = {
        videoTileDidUpdate(tileState) {
            if (!tileState.boundAttendeeId || tileState.isContent) return

            const id = tileState.localTile ? 'local' : tileState.boundAttendeeId
            tileIdToParticipantId.set(tileState.tileId, id)
            callbacks.onVideoAvailable?.(id)

            const element = attachedElements.get(id)
            if (element) meetingSession.audioVideo.bindVideoElement(tileState.tileId, element)
        },
        videoTileWasRemoved(tileId) {
            tileIdToParticipantId.delete(tileId)
        },
        connectionDidBecomePoor() {
            callbacks.onConnectionQualityChanged?.('poor')
        },
        connectionDidBecomeGood() {
            callbacks.onConnectionQualityChanged?.('good')
        },
        audioVideoDidStop(sessionStatus) {
            // A non-explicit stop (we didn't call teardown() ourselves) means the connection
            // dropped out from under us -- a disconnect, not just a reportable error.
            if (intentionalTeardown) return
            callbacks.onDisconnected?.(new Error(`Video connection stopped unexpectedly (status ${sessionStatus?.statusCode?.()}).`))
        },
    }

    async function join(credentials) {
        localAttendeeId = credentials.attendee.AttendeeId

        const logger = new ConsoleLogger('ChimeMeetingLogs', LogLevel.WARN)
        const deviceController = new DefaultDeviceController(logger)
        const configuration = new MeetingSessionConfiguration(credentials.meeting, credentials.attendee)
        meetingSession = new DefaultMeetingSession(configuration, logger, deviceController)

        const mics = await meetingSession.audioVideo.listAudioInputDevices()
        if (mics[0]) await meetingSession.audioVideo.startAudioInput(mics[0].deviceId)

        const cams = await meetingSession.audioVideo.listVideoInputDevices()
        if (cams[0]) await meetingSession.audioVideo.startVideoInput(cams[0].deviceId)

        meetingSession.audioVideo.addObserver(observer)

        presenceCallback = (attendeeId, present) => {
            // Our own join is reported explicitly below, right after starting the local tile --
            // filtered here so it's never double-reported regardless of whether Chime's presence
            // stream also happens to include the local attendee.
            if (attendeeId === localAttendeeId) return

            if (present) callbacks.onParticipantJoined?.({ id: attendeeId, isLocal: false, name: null })
            else callbacks.onParticipantLeft?.(attendeeId)
        }
        meetingSession.audioVideo.realtimeSubscribeToAttendeeIdPresence(presenceCallback)

        meetingSession.audioVideo.start()
        meetingSession.audioVideo.startLocalVideoTile()
        videoEnabled = true

        callbacks.onParticipantJoined?.({ id: 'local', isLocal: true, name: null })
    }

    function attachVideo(id, element) {
        attachedElements.set(id, element)

        for (const [tileId, participantId] of tileIdToParticipantId) {
            if (participantId === id) meetingSession?.audioVideo.bindVideoElement(tileId, element)
        }
    }

    function toggleMute() {
        if (!meetingSession) return true
        const isMuted = meetingSession.audioVideo.realtimeIsLocalAudioMuted()
        if (isMuted) meetingSession.audioVideo.realtimeUnmuteLocalAudio()
        else meetingSession.audioVideo.realtimeMuteLocalAudio()
        return !isMuted
    }

    function toggleCamera() {
        if (!meetingSession) return videoEnabled
        videoEnabled = !videoEnabled
        if (videoEnabled) meetingSession.audioVideo.startLocalVideoTile()
        else meetingSession.audioVideo.stopLocalVideoTile()
        return videoEnabled
    }

    // Docs are explicit that stop() does not clean up observers/subscriptions itself -- this
    // order (tile off, inputs stopped, presence unsubscribed, observer removed, THEN stop) is
    // the sequence amazon-chime-sdk-js's own guide recommends.
    async function teardown() {
        if (!meetingSession) return

        intentionalTeardown = true
        meetingSession.audioVideo.stopLocalVideoTile()
        await meetingSession.audioVideo.stopVideoInput()
        await meetingSession.audioVideo.stopAudioInput()
        if (presenceCallback) meetingSession.audioVideo.realtimeUnsubscribeToAttendeeIdPresence(presenceCallback)
        meetingSession.audioVideo.removeObserver(observer)
        await meetingSession.audioVideo.stop()
    }

    // Deliberately identical -- there is no client-side "end meeting for everyone" API in this
    // SDK at all. Ending the meeting for everyone is exclusively our backend's server-side
    // DeleteMeeting API call (ChimeClient::deleteMeeting).
    function leave() { return teardown() }
    function end() { return teardown() }

    async function destroy() {
        await teardown()
        meetingSession = null
        attachedElements.clear()
        tileIdToParticipantId.clear()
    }

    return { join, leave, end, toggleMute, toggleCamera, attachVideo, destroy }
}
