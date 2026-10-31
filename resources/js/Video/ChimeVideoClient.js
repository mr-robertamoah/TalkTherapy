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
    let audioInputStarted = false
    let videoInputStarted = false
    // Bumped by disableLocalMedia() -- a toggle still awaiting a device start when a revoke lands
    // must not switch the mic/camera back on afterwards (re-review finding).
    let mediaGeneration = 0
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

        // TT-3.2f-j/SCRUM-327: a receive-only member (or, for video, the audio-only creator of an
        // anonymous group) never opens a device it can't send from -- started lazily instead, by
        // toggleMute()/toggleCamera(), once a speaking grant arrives. The attendee's own Chime
        // capabilities enforce this regardless.
        const capabilities = credentials.participant ?? { canSendAudio: true, canSendVideo: true }

        if (capabilities.canSendAudio) await startAudioInput()
        if (capabilities.canSendVideo) await startVideoInput()

        meetingSession.audioVideo.addObserver(observer)

        presenceCallback = (attendeeId, present, externalUserId) => {
            // Our own join is reported explicitly below, right after starting the local tile --
            // filtered here so it's never double-reported regardless of whether Chime's presence
            // stream also happens to include the local attendee.
            if (attendeeId === localAttendeeId) return

            // TT-3.2c/SCRUM-310: externalUserId is our OWN backend user id -- exactly what
            // ChimeVideoProvider::createParticipantCredentials() sent as ExternalUserId when
            // minting this attendee. May be undefined on some presence updates per the SDK's own
            // docs; Number(undefined) is NaN, so this is normalized to null rather than a bogus
            // numeric id useVideoSession.js's own patch-in-later logic could mistake for real.
            const userId = externalUserId != null ? Number(externalUserId) : null

            if (present) callbacks.onParticipantJoined?.({ id: attendeeId, isLocal: false, name: null, userId })
            else callbacks.onParticipantLeft?.(attendeeId)
        }
        meetingSession.audioVideo.realtimeSubscribeToAttendeeIdPresence(presenceCallback)

        meetingSession.audioVideo.start()
        if (capabilities.canSendVideo) {
            meetingSession.audioVideo.startLocalVideoTile()
            videoEnabled = true
        }
        if (!capabilities.canSendAudio) meetingSession.audioVideo.realtimeMuteLocalAudio()

        // TT-3.2c/SCRUM-310: credentials.attendee is this same session's own CreateAttendee
        // response (see ChimeVideoProvider::createParticipantCredentials()), which already
        // carries the ExternalUserId this attendee was minted with -- no separate lookup needed
        // for the local participant's own userId.
        const localExternalUserId = credentials.attendee?.ExternalUserId
        callbacks.onParticipantJoined?.({
            id: 'local',
            isLocal: true,
            name: null,
            userId: localExternalUserId != null ? Number(localExternalUserId) : null,
        })
    }

    function attachVideo(id, element) {
        attachedElements.set(id, element)

        for (const [tileId, participantId] of tileIdToParticipantId) {
            if (participantId === id) meetingSession?.audioVideo.bindVideoElement(tileId, element)
        }
    }

    async function startAudioInput() {
        const mics = await meetingSession.audioVideo.listAudioInputDevices()
        if (mics[0]) await meetingSession.audioVideo.startAudioInput(mics[0].deviceId)
        audioInputStarted = true
    }

    async function startVideoInput() {
        const cams = await meetingSession.audioVideo.listVideoInputDevices()
        if (cams[0]) await meetingSession.audioVideo.startVideoInput(cams[0].deviceId)
        videoInputStarted = true
    }

    // Async (unlike Daily's) because a receive-only join opens no devices -- the first unmute or
    // camera-on after a speaking grant has to start the input first. State flips only once the
    // device has actually started (a denied permission rejects, leaving everything off), and a
    // teardown landing during that await is respected rather than resurrecting the media.
    // useVideoSession.js serializes calls, so two toggles never interleave.
    async function toggleMute() {
        if (!meetingSession) return true
        const isMuted = meetingSession.audioVideo.realtimeIsLocalAudioMuted()
        if (!isMuted) {
            meetingSession.audioVideo.realtimeMuteLocalAudio()
            return true
        }

        const generation = mediaGeneration
        if (!audioInputStarted) await startAudioInput()
        if (!meetingSession) return true
        if (generation !== mediaGeneration) {
            await disableLocalMedia()
            return true
        }
        meetingSession.audioVideo.realtimeUnmuteLocalAudio()
        return false
    }

    async function toggleCamera() {
        if (!meetingSession) return videoEnabled
        if (videoEnabled) {
            meetingSession.audioVideo.stopLocalVideoTile()
            videoEnabled = false
            return false
        }

        const generation = mediaGeneration
        if (!videoInputStarted) await startVideoInput()
        if (!meetingSession) return false
        if (generation !== mediaGeneration) {
            await disableLocalMedia()
            return false
        }
        meetingSession.audioVideo.startLocalVideoTile()
        videoEnabled = true
        return true
    }

    // TT-3.2f-j/SCRUM-327: see DailyVideoClient.js's own disableLocalMedia(). Also releases the
    // devices themselves, so the browser's camera/mic indicator goes off after a revoke -- a later
    // grant starts them again lazily, exactly like a receive-only join.
    async function disableLocalMedia() {
        mediaGeneration++
        if (!meetingSession) return
        meetingSession.audioVideo.realtimeMuteLocalAudio()
        meetingSession.audioVideo.stopLocalVideoTile()
        videoEnabled = false
        await meetingSession.audioVideo.stopVideoInput()
        await meetingSession.audioVideo.stopAudioInput()
        videoInputStarted = false
        audioInputStarted = false
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

    return { join, leave, end, toggleMute, toggleCamera, disableLocalMedia, attachVideo, destroy }
}
