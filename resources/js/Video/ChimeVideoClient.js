import {
    ConsoleLogger,
    DefaultDeviceController,
    DefaultMeetingSession,
    LogLevel,
    MeetingSessionConfiguration,
    MeetingSessionStatusCode,
} from 'amazon-chime-sdk-js'
import { chimeQuality, classifyChimeStop } from '@/Video/connectionPolicy'
import { NETWORK_PAUSE_TOPIC, decodeNetworkPauseText, networkPauseMessage } from '@/Video/audioOnlyPolicy'

// TT-3.1c/SCRUM-276: thin wrapper around amazon-chime-sdk-js's meeting-session API -- see
// DailyVideoClient.js for the Daily.co counterpart and the factory shape both must implement
// (documented at the top of useVideoSession.js).
//
// Chime's CreateAttendee API has no display-name concept at all -- ChimeVideoProvider only sends
// ExternalUserId (the participant key, SCRUM-357), set server-side when the attendee is created and
// not changeable from the browser. So there is no provider-side name to trust or distrust here:
// useVideoSession.js resolves every label from our server by that key (SCRUM-334), exactly as it
// does for Daily.
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
    // TT-3.3c/SCRUM-460: the last camera state reported per remote attendee (only changes are
    // reported), and whether this browser receives other people's video ("audio only" mode).
    const remoteVideoOn = new Map()
    let receivingVideo = true

    function reportRemoteVideo(id, isOn) {
        if (remoteVideoOn.get(id) === isOn) return
        remoteVideoOn.set(id, isOn)
        callbacks.onRemoteVideoChanged?.(id, isOn)
    }

    const observer = {
        videoTileDidUpdate(tileState) {
            if (!tileState.boundAttendeeId || tileState.isContent) return

            const id = tileState.localTile ? 'local' : tileState.boundAttendeeId
            tileIdToParticipantId.set(tileState.tileId, id)

            if (!tileState.localTile) {
                // TT-3.3c/SCRUM-460: "audio only" pauses every remote tile, including ones that
                // arrive after it was switched on. A tile WE paused says nothing about their camera.
                if (!receivingVideo && !tileState.paused) {
                    meetingSession.audioVideo.pauseVideoTile(tileState.tileId)
                    return
                }
                if (!tileState.paused) reportRemoteVideo(id, !!tileState.active)
            }

            callbacks.onVideoAvailable?.(id)

            const element = attachedElements.get(id)
            if (element) meetingSession.audioVideo.bindVideoElement(tileState.tileId, element)
        },
        videoTileWasRemoved(tileId) {
            // TT-3.3c/SCRUM-460: a remote tile goes when that person's camera stops sending.
            const id = tileIdToParticipantId.get(tileId)
            tileIdToParticipantId.delete(tileId)
            if (id && id !== 'local') reportRemoteVideo(id, false)
        },
        // TT-3.3b/SCRUM-459: mapped onto the shared quality scale -- see connectionPolicy.js.
        connectionDidBecomePoor() {
            callbacks.onConnectionQualityChanged?.(chimeQuality('connectionDidBecomePoor'))
        },
        connectionDidSuggestStopVideo() {
            callbacks.onConnectionQualityChanged?.(chimeQuality('connectionDidSuggestStopVideo'))
        },
        connectionDidBecomeGood() {
            callbacks.onConnectionQualityChanged?.(chimeQuality('connectionDidBecomeGood'))
        },
        // TT-3.3b/SCRUM-459: Chime reconnecting by itself -- audioVideoDidStartConnecting(true) when
        // it starts, audioVideoDidStart once connected again (also called, harmlessly, on the
        // first connect). It keeps trying for reconnectTimeoutMs before giving up with
        // audioVideoDidStop.
        audioVideoDidStartConnecting(reconnecting) {
            if (reconnecting && !intentionalTeardown) callbacks.onReconnecting?.(true)
        },
        audioVideoDidStart() {
            if (!intentionalTeardown) callbacks.onReconnecting?.(false)
        },
        audioVideoDidStop(sessionStatus) {
            // A non-explicit stop (we didn't call teardown() ourselves) means the connection
            // ended out from under us. TT-3.3b/SCRUM-459: classified by status code -- this also
            // fires for the meeting ending, being removed, joining from another tab, etc., none of
            // which a rejoin may undo. Only connectionPolicy.js's allow-list is ever rejoined.
            if (intentionalTeardown) return
            const code = sessionStatus?.statusCode?.()
            callbacks.onDisconnected?.(classifyChimeStop(MeetingSessionStatusCode[code]))
        },
    }

    async function join(credentials, { startVideoOff = false } = {}) {
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
        // TT-3.3c/SCRUM-460: a rejoin while video was paused (or in "audio only") keeps it off --
        // started lazily later, exactly like a receive-only join's camera.
        const startVideo = capabilities.canSendVideo && !startVideoOff

        if (capabilities.canSendAudio) await startAudioInput()
        if (startVideo) await startVideoInput()

        meetingSession.audioVideo.addObserver(observer)

        presenceCallback = (attendeeId, present, externalUserId) => {
            // Our own join is reported explicitly below, right after starting the local tile --
            // filtered here so it's never double-reported regardless of whether Chime's presence
            // stream also happens to include the local attendee.
            if (attendeeId === localAttendeeId) return

            // TT-3.2c/SCRUM-310, SCRUM-357: externalUserId is the participant key
            // ChimeVideoProvider::createParticipantCredentials() sent as ExternalUserId when
            // minting this attendee -- never a user id. May be undefined on some presence updates
            // per the SDK's own docs; normalized to null so useVideoSession.js's patch-in-later
            // logic never mistakes it for a real key.
            const userId = externalUserId ?? null

            if (present) {
                callbacks.onParticipantJoined?.({ id: attendeeId, isLocal: false, userId })
            } else {
                remoteVideoOn.delete(attendeeId)
                callbacks.onParticipantLeft?.(attendeeId)
            }
        }
        meetingSession.audioVideo.realtimeSubscribeToAttendeeIdPresence(presenceCallback)

        // TT-3.3c/SCRUM-460: another person's "my video is paused for my connection" flag, on our
        // own topic. Attributed by Chime's own senderAttendeeId (the same id as their tile), never
        // by anything in the payload, which must be exactly { ttNetworkPause: boolean }. A throttled
        // message is Chime telling the sender theirs wasn't delivered -- not a message to act on.
        meetingSession.audioVideo.realtimeSubscribeToReceiveDataMessage(NETWORK_PAUSE_TOPIC, (message) => {
            if (!message || message.throttled || message.senderAttendeeId === localAttendeeId) return
            if (typeof message.senderAttendeeId !== 'string') return
            let text = null
            try {
                text = message.text()
            } catch (err) {
                return
            }
            const paused = decodeNetworkPauseText(text)
            if (paused !== null) callbacks.onRemoteNetworkPause?.(message.senderAttendeeId, paused)
        })

        meetingSession.audioVideo.start()
        if (startVideo) {
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
            userId: localExternalUserId ?? null,
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

    // TT-3.3c/SCRUM-460: the app pausing/restoring the camera for the connection -- never the mic.
    // Returns the new isCameraOn; the same device-start and revoke-race handling as toggleCamera().
    async function setCamera(on) {
        if (!!on === videoEnabled) return videoEnabled
        return toggleCamera()
    }

    // TT-3.3c/SCRUM-460: "audio only"'s receive side -- every remote tile paused (new ones too, see
    // videoTileDidUpdate), or all of them resumed.
    function setReceiveVideo(on) {
        receivingVideo = !!on
        if (!meetingSession) return
        for (const tile of meetingSession.audioVideo.getAllRemoteVideoTiles()) {
            const tileId = tile.state().tileId
            if (receivingVideo) meetingSession.audioVideo.unpauseVideoTile(tileId)
            else meetingSession.audioVideo.pauseVideoTile(tileId)
        }
    }

    // TT-3.3c/SCRUM-460: tell everyone else our video is (no longer) paused for the connection.
    // Best effort, not kept for people who join later (useVideoSession.js re-sends it to them).
    function sendNetworkPause(paused) {
        try {
            meetingSession?.audioVideo.realtimeSendDataMessage(NETWORK_PAUSE_TOPIC, networkPauseMessage(paused))
        } catch (err) {
            // Not started (yet/any more) -- nothing to tell.
        }
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
        meetingSession.audioVideo.realtimeUnsubscribeFromReceiveDataMessage(NETWORK_PAUSE_TOPIC)
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
        remoteVideoOn.clear()
    }

    return { join, leave, end, toggleMute, toggleCamera, setCamera, setReceiveVideo, sendNetworkPause, disableLocalMedia, attachVideo, destroy }
}
