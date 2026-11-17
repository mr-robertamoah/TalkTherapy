// TT-3.3c/SCRUM-460: the rules for dropping to audio only on a weak connection, kept pure and
// dependency-free like connectionPolicy.js (tests/js/audioOnlyPolicy.test.mjs, plain `node --test`).
// useVideoSession.js owns the timers and the provider calls; everything it decides comes from here.
// Nothing here touches Session.status, attendance or anything server-side -- it is all local media.
import { QUALITY_BAD, QUALITY_GOOD, QUALITY_POOR, normalizeQuality } from './connectionPolicy.js'

// ---------------------------------------------------------------------------------------------
// Pausing and restoring the person's own camera
// ---------------------------------------------------------------------------------------------

// A 'poor' connection must last this long before the camera is paused; 'bad' pauses at once.
export const AUTO_PAUSE_POOR_MS = 10000
// A paused camera comes back only after this long of 'good'.
export const AUTO_RESTORE_GOOD_MS = 30000
// After this many automatic restores in a call, a paused camera stays paused until the person
// turns it back on themselves -- a line flapping between good and weak would otherwise bounce
// their video on and off all call long.
export const MAX_AUTO_RESTORES = 2

export const AUTO_PAUSE = 'pause'
export const AUTO_RESTORE = 'restore'

const NO_ACTION = Object.freeze({ action: null, inMs: null })

// Whether the server currently lets this person send video at all: the join response's
// capabilities, kept current by the speaking-permission broadcasts. A receive-only member counts
// only while they hold a speaking grant (canSendAudio); the anonymous group creator and a minor at
// "watch and listen" have canSendVideo false; any revoke or downgrade flips these. The provider
// token is what really enforces it -- this only stops the app ever asking for video it can't send.
export function isVideoAllowed(capabilities) {
    if (!capabilities?.canSendVideo) return false
    if (capabilities.receiveOnly && !capabilities.canSendAudio) return false
    return true
}

// What the app should do with the person's camera, and in how long (0: now), or
// { action: null } for nothing. Re-asked on every quality change and whenever a scheduled action
// comes due, so a timer only ever acts on a decision that still holds.
//
//  - quality / qualityForMs: the current reading and how long it has held.
//  - cameraOn: the camera as it actually is; cameraWanted: what the PERSON last chose. The app
//    pauses only a camera the person wants on, and restores only one IT paused (pausedByApp) --
//    never one the person turned off, never one that was never allowed.
//  - videoAllowed: isVideoAllowed(capabilities) right now.
//  - audioOnly: the person's own "audio only" mode -- the app leaves the camera alone meanwhile.
//  - restoresUsed: automatic restores so far this call (MAX_AUTO_RESTORES caps them).
//  - autoPauseSuspended: the person turned video back on themselves -- not paused again until
//    the connection changes, so their own choice isn't undone the moment they make it.
export function autoVideoDecision({
    quality,
    qualityForMs = 0,
    cameraOn = false,
    cameraWanted = false,
    pausedByApp = false,
    videoAllowed = false,
    audioOnly = false,
    restoresUsed = 0,
    autoPauseSuspended = false,
} = {}) {
    if (audioOnly || !videoAllowed || !cameraWanted) return NO_ACTION

    const q = normalizeQuality(quality)
    const heldFor = Number.isFinite(qualityForMs) && qualityForMs > 0 ? qualityForMs : 0

    if (pausedByApp) {
        if (cameraOn || restoresUsed >= MAX_AUTO_RESTORES || q !== QUALITY_GOOD) return NO_ACTION
        return { action: AUTO_RESTORE, inMs: Math.max(0, AUTO_RESTORE_GOOD_MS - heldFor) }
    }

    if (!cameraOn || autoPauseSuspended) return NO_ACTION
    if (q === QUALITY_BAD) return { action: AUTO_PAUSE, inMs: 0 }
    if (q === QUALITY_POOR) return { action: AUTO_PAUSE, inMs: Math.max(0, AUTO_PAUSE_POOR_MS - heldFor) }

    return NO_ACTION
}

// ---------------------------------------------------------------------------------------------
// Telling everyone else why the video stopped
// ---------------------------------------------------------------------------------------------

// The provider data-message topic (Chime's realtimeSendDataMessage; Daily's app messages have no
// topic, the payload's shape is the whole check). The payload is exactly { ttNetworkPause: bool }
// -- nothing about who sent it (the provider's own sender id says that), and nothing about age,
// consent level or anonymity.
export const NETWORK_PAUSE_TOPIC = 'tt-net-pause'

export function networkPauseMessage(paused) {
    return { ttNetworkPause: paused === true }
}

// The payload's boolean, or null for anything that isn't exactly a network-pause message: not a
// plain object, any other key, a non-boolean value. Another app's message on the same channel, or
// a malformed one, is simply ignored.
export function parseNetworkPauseMessage(data) {
    if (data === null || typeof data !== 'object' || Array.isArray(data)) return null

    const proto = Object.getPrototypeOf(data)
    if (proto !== Object.prototype && proto !== null) return null

    const keys = Object.keys(data)
    if (keys.length !== 1 || keys[0] !== 'ttNetworkPause') return null

    return typeof data.ttNetworkPause === 'boolean' ? data.ttNetworkPause : null
}

// The longest raw text accepted (Chime hands the payload over as bytes/text). The real message is at most
// 24 characters; anything much longer isn't ours.
export const MAX_NETWORK_PAUSE_TEXT = 64

// parseNetworkPauseMessage() for a payload that arrives as text.
export function decodeNetworkPauseText(text) {
    if (typeof text !== 'string' || text.length === 0 || text.length > MAX_NETWORK_PAUSE_TEXT) return null

    try {
        return parseNetworkPauseMessage(JSON.parse(text))
    } catch {
        return null
    }
}

// ---------------------------------------------------------------------------------------------
// Other people's tiles
// ---------------------------------------------------------------------------------------------

// Daily's remote participant.tracks.video.state as "is their camera sending": true, false, or null
// for "no change worth showing" (interrupted: a blip Daily is already recovering; anything unknown).
export function dailyRemoteVideoOn(trackState) {
    if (['playable', 'loading', 'sendable'].includes(trackState)) return true
    if (['off', 'blocked'].includes(trackState)) return false
    return null
}

// A remote person's tile state after their camera changed: a network-pause label stays only while
// their video is off -- it clears the moment it comes back. `entry` is { videoOff, networkPaused }.
export function remoteVideoChanged(entry, isOn) {
    const was = entry ?? { videoOff: false, networkPaused: false }
    const videoOff = !isOn

    return { videoOff, networkPaused: was.videoOff && isOn ? false : !!was.networkPaused }
}

// A remote person's tile state after their network-pause message.
export function remoteNetworkPauseChanged(entry, paused) {
    return { videoOff: !!entry?.videoOff, networkPaused: paused === true }
}

export const OVERLAY_NETWORK = 'network'
export const OVERLAY_CAMERA_OFF = 'camera-off'
export const OVERLAY_AUDIO_ONLY = 'audio-only'

// What covers a tile instead of its video, or null for the video itself. The network reason wins:
// it says why, where "camera off" only says that.
export function tileOverlay({ networkPaused = false, videoOff = false, receivingVideo = true } = {}) {
    if (networkPaused) return OVERLAY_NETWORK
    if (videoOff) return OVERLAY_CAMERA_OFF
    if (!receivingVideo) return OVERLAY_AUDIO_ONLY
    return null
}
